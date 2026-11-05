<?php

declare(strict_types=1);

namespace ExeQue\LumberjackAgent;

use Closure;
use Throwable;

/**
 * Collects batches from one app's processes on this server and sends them on
 * to Lumberjack with that app's token: every few seconds or at 1 MB, with retry
 * and a disk spool.
 *
 * One agent serves one project. Several apps on a server each run their own
 * agent on their own port; a batch from an app of another project (one that
 * points at the wrong port) is turned away, not sent under this token.
 */
class Agent
{
    public const int FLUSH_BYTES = 1_048_576;

    public const int EVENTS_PER_BATCH = 1000;

    public const int HEARTBEAT_SECONDS = 60;

    public const int MAX_BACKOFF = 300;

    /**
     * How long one flush or one pass over the spool may keep sending before the agent goes
     * back to accepting connections (plus at most one send's timeout). The agent is one
     * thread: while it sends, apps' connections wait in the listen backlog.
     */
    public const float SEND_BUDGET = 0.25;

    private float $budgetEnds = 0.0;

    /** @var array<string, array{envelope: array<string, mixed>, events: list<mixed>}> */
    private array $batches = [];

    private int $bytes = 0;

    private float $lastFlush;

    private float $lastHeartbeat = 0.0;

    private float $retryAt = 0.0;

    private int $failures = 0;

    private bool $stopRequested = false;

    /** @var array<string, true> Projects already warned about. */
    private array $foreign = [];

    public function __construct(
        private readonly Sender $sender,
        private readonly Spool $spool,
        private readonly int $interval = 5,
        private readonly ?string $host = null,
        private readonly ?string $name = null,
        private readonly ?string $project = null,
        private readonly ?Closure $warn = null,
        private readonly ?Closure $status = null,
        private readonly ?Closure $health = null,
        private readonly ?string $key = null,
        /** @var array<string, array{name: string, version: string}>|null what sends the heartbeat; the agent alone when null */
        private readonly ?array $client = null,
    ) {
        $this->lastFlush = microtime(true);
    }

    /**
     * One newline-delimited payload from an app.
     */
    public function receive(string $line): void
    {
        $payload = json_decode($line, true);

        if (! is_array($payload)) {
            return;
        }

        $from = is_string($payload['project'] ?? null) ? $payload['project'] : null;

        // Another project's app on this port: its key differs too, but this says why.
        if ($from !== null && $this->project !== null && ! hash_equals($this->project, $from)) {
            $this->warnOnce($from, "Turned away data from an app of another project (mark {$from}). Give each app its own agent and LUMBERJACK_AGENT_PORT.");

            return;
        }

        // Only an app that knows the key may hand over data or stop the agent.
        if ($this->key !== null && ! (is_string($payload['key'] ?? null) && hash_equals($this->key, $payload['key']))) {
            $this->warnOnce('', 'Turned away a line without the agent key. The app and the agent need the same LUMBERJACK_TOKEN (or LUMBERJACK_AGENT_KEY) and the same package version.');

            return;
        }

        // php artisan lumberjack:terminate, e.g. after a deploy.
        if (($payload['control'] ?? null) === 'terminate') {
            $this->stopRequested = true;

            return;
        }

        if (! is_array($payload['events'] ?? null)) {
            return;
        }

        $envelope = [
            'v' => 1,
            'sdk' => $payload['sdk'] ?? null,
            'environment' => $payload['environment'] ?? null,
            'release' => $payload['release'] ?? null,
            'host' => $payload['host'] ?? $this->host,
        ];
        $key = md5(serialize($envelope));

        // Request counts go in batches of their own: a server from before them refuses a
        // batch holding one, and that must not cost the rest of the data.
        foreach ($payload['events'] as $event) {
            $batch = is_array($event) && ($event['type'] ?? null) === 'request_counts' ? $key.':counts' : $key;
            $this->batches[$batch] ??= ['envelope' => $envelope, 'events' => []];
            $this->batches[$batch]['events'][] = $event;
        }
        $this->bytes += strlen($line);

        if ($this->bytes >= self::FLUSH_BYTES) {
            $this->flush(microtime(true));
        }
    }

    private function warnOnce(string $about, string $message): void
    {
        // ponytail: at most 100 remembered, so a flood of made-up marks cannot grow it.
        if (isset($this->foreign[$about]) || count($this->foreign) >= 100) {
            return;
        }

        $this->foreign[$about] = true;
        ($this->warn)?->__invoke($message);
    }

    public function tick(float $now): void
    {
        if ($now - $this->lastFlush >= $this->interval) {
            $this->flush($now);
        }

        $this->retrySpool($now);

        if ($now - $this->lastHeartbeat >= self::HEARTBEAT_SECONDS) {
            $this->lastHeartbeat = $now;
            $answer = $this->sender->heartbeat($this->host ?? (gethostname() ?: 'unknown'), $this->client ?? AgentInfo::describe(), $this->name, $this->status());
            $this->storeHealth($answer['health'] ?? null);
        }
    }

    /**
     * Hands the health states the heartbeat got back to the app's store. A store that
     * fails (its cache is down) costs the states, never the agent.
     */
    private function storeHealth(mixed $health): void
    {
        if (! is_array($health)) {
            return;
        }

        try {
            ($this->health)?->__invoke($health);
        } catch (Throwable) {
        }
    }

    /**
     * What the heartbeat says about the app beyond being alive, e.g. queue sizes.
     * A probe that fails costs its part, never the heartbeat.
     *
     * @return array<string, mixed>
     */
    private function status(): array
    {
        try {
            $status = ($this->status)?->__invoke();
        } catch (Throwable) {
            return [];
        }

        return is_array($status) ? $status : [];
    }

    public function flush(float $now): void
    {
        $this->lastFlush = $now;
        $this->budgetEnds = microtime(true) + self::SEND_BUDGET;
        $batches = $this->batches;
        $this->batches = [];
        $this->bytes = 0;

        foreach ($batches as $batch) {
            foreach (array_chunk($batch['events'], self::EVENTS_PER_BATCH) as $events) {
                $body = gzencode((string) json_encode([...$batch['envelope'], 'events' => $events], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES));

                if ($body !== false) {
                    $this->deliver($body, $now);
                }
            }
        }
    }

    public function stopRequested(): bool
    {
        return $this->stopRequested;
    }

    public function backingOff(float $now): bool
    {
        return $now < $this->retryAt;
    }

    private function deliver(string $body, float $now): void
    {
        // While Lumberjack is down, new data waits its turn behind the spool; past the
        // budget, it waits there for the next tick.
        if ($this->backingOff($now) || microtime(true) > $this->budgetEnds || $this->spool->count() > 0) {
            $this->spool->push($body);

            return;
        }

        $result = $this->sender->send($body);

        if ($result === SendResult::Retry) {
            $this->failed($now);
            $this->spool->push($body);

            return;
        }

        if ($result === SendResult::Rejected) {
            $this->warnOnce('#rejected', 'Lumberjack refused a batch as such (a 4xx); it was dropped. Further refusals are not reported.');
        }

        $this->failures = 0;
    }

    private function retrySpool(float $now): void
    {
        $this->budgetEnds = microtime(true) + self::SEND_BUDGET;

        while (! $this->backingOff($now) && microtime(true) <= $this->budgetEnds && ($file = $this->spool->oldest()) !== null) {
            $body = $this->spool->read($file);

            if ($body !== null && $this->sender->send($body) === SendResult::Retry) {
                $this->failed($now);

                return;
            }

            $this->failures = 0;
            $this->spool->delete($file);
        }
    }

    private function failed(float $now): void
    {
        $this->failures++;
        $this->retryAt = $now + min(self::MAX_BACKOFF, $this->interval * 2 ** ($this->failures - 1));
    }
}

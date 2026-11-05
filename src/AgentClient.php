<?php

declare(strict_types=1);

namespace ExeQue\LumberjackAgent;

/**
 * Hands a batch to the local agent. Never blocks the app for long and never
 * throws: when the agent is not there, the batch is dropped.
 *
 * After a failed send it stops trying for a few seconds (a circuit breaker), so a
 * long-running worker does not pay the connect timeout on every job while the agent
 * is down or stuck. What was dropped meanwhile is reported, as a warning log line,
 * with the first batch the agent takes again.
 *
 * Every line carries the agent key, so the agent takes data and commands only from
 * apps that know it. A host name is looked up once a minute, not on every batch.
 * With APCu, the lookup and the breaker are shared by the processes of a PHP-FPM pool.
 */
class AgentClient
{
    public const float CONNECT_TIMEOUT = 0.05;

    /** For the whole batch (all its parts), not per write. */
    public const float WRITE_TIMEOUT = 1.0;

    public const int BREAK_SECONDS = 5;

    public const int RESOLVE_SECONDS = 60;

    /** The longest line the agent takes; a larger batch is sent in parts. */
    public const int MAX_LINE = 4 * 1024 * 1024;

    private float $closedUntil = 0.0;

    private float $deadline = 0.0;

    private int $dropped = 0;

    /** @var array{0: ?string, 1: float}|null the address the host resolved to, and when */
    private ?array $resolved = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $breakSeconds = self::BREAK_SECONDS,
        private readonly ?string $key = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): bool
    {
        $now = microtime(true);

        if ($now < max($this->closedUntil, (float) $this->shared('closed'))) {
            $this->dropped++;

            return false;
        }

        if ($this->dropped > 0 && is_array($payload['events'] ?? null)) {
            $payload['events'][] = [
                'type' => 'log',
                'level' => 'warning',
                'message' => "Lumberjack dropped {$this->dropped} batches while the agent could not be reached.",
                'trace_id' => bin2hex(random_bytes(16)),
                'timestamp' => $now,
            ];
        }

        $this->deadline = microtime(true) + self::WRITE_TIMEOUT;

        if (! $this->deliver($payload)) {
            $this->closedUntil = microtime(true) + $this->breakSeconds;
            $this->share('closed', $this->closedUntil, (int) ceil($this->breakSeconds));
            $this->dropped++;

            return false;
        }

        $this->dropped = 0;

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(array $payload): bool
    {
        if ($this->key !== null) {
            $payload['key'] = $this->key;
        }

        $line = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES, 64);

        // ponytail: a batch that cannot be encoded is dropped; the agent is not to blame.
        if ($line === false) {
            return true;
        }

        $events = $payload['events'] ?? null;

        if (strlen($line) > self::MAX_LINE && is_array($events) && count($events) > 1) {
            $half = intdiv(count($events), 2);

            return $this->deliver(['events' => array_slice($events, 0, $half)] + $payload)
                && $this->deliver(['events' => array_slice($events, $half)] + $payload);
        }

        return $this->write($line);
    }

    protected function write(string $line): bool
    {
        $address = $this->address();

        if ($address === null) {
            return false;
        }

        $socket = @stream_socket_client("tcp://{$address}:{$this->port}", $errno, $error, self::CONNECT_TIMEOUT);

        if ($socket === false) {
            return false;
        }

        $data = $line."\n";

        while ($data !== '') {
            $left = $this->deadline - microtime(true);

            if ($left <= 0) {
                fclose($socket);

                return false;
            }

            stream_set_timeout($socket, (int) $left, (int) (($left - (int) $left) * 1e6));
            $written = @fwrite($socket, $data);

            if ($written === false || $written === 0) {
                fclose($socket);

                return false;
            }

            $data = substr($data, $written);
        }

        fclose($socket);

        return true;
    }

    /**
     * The host as an IP: as it is when it is one, else looked up once per minute.
     * ponytail: gethostbyname() has no timeout of its own; a failed lookup opens the breaker.
     */
    private function address(): ?string
    {
        if (filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '['.$this->host.']';
        }

        if (filter_var($this->host, FILTER_VALIDATE_IP) !== false) {
            return $this->host;
        }

        $now = microtime(true);

        if ($this->resolved !== null && $now - $this->resolved[1] < self::RESOLVE_SECONDS) {
            return $this->resolved[0];
        }

        $ip = $this->shared('ip');

        if (! is_string($ip)) {
            $ip = gethostbyname($this->host);
            $ip = filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
            $this->share('ip', $ip, self::RESOLVE_SECONDS);
        }

        $this->resolved = [$ip !== '' ? $ip : null, $now];

        return $this->resolved[0];
    }

    private function shared(string $name): mixed
    {
        if (! function_exists('apcu_fetch') || ! apcu_enabled()) {
            return null;
        }

        $value = apcu_fetch("lumberjack:agent:{$this->host}:{$this->port}:{$name}", $found);

        return $found ? $value : null;
    }

    private function share(string $name, mixed $value, int $ttl): void
    {
        if (function_exists('apcu_store') && apcu_enabled()) {
            apcu_store("lumberjack:agent:{$this->host}:{$this->port}:{$name}", $value, $ttl);
        }
    }
}

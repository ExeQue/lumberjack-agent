<?php

declare(strict_types=1);

use ExeQue\LumberjackAgent\Agent;
use ExeQue\LumberjackAgent\AgentInfo;
use ExeQue\LumberjackAgent\ProjectFingerprint;
use ExeQue\LumberjackAgent\Sender;
use ExeQue\LumberjackAgent\Spool;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\Support\FakeLumberjack;

beforeEach(function () {
    $this->spoolPath = sys_get_temp_dir().'/lumberjack-spool-'.bin2hex(random_bytes(4));
    $this->spool = new Spool($this->spoolPath, 10_000);
    $this->lumberjack = new FakeLumberjack();
    $this->sender = fn (): Sender => new Sender('https://lumberjack.test', 'secret-token', http: $this->lumberjack->client());
    $this->makeAgent = fn (mixed ...$options): Agent => new Agent(($this->sender)(), $this->spool, 5, 'web-1', ...$options);
    $this->line = fn (array $events, string $env = 'production'): string => (string) json_encode(['v' => 1, 'sdk' => 'test', 'environment' => $env, 'release' => 'r1', 'host' => 'web-1', 'events' => $events]);
});

afterEach(fn () => removeDirectory($this->spoolPath));

it('sends gzipped batches with the bearer token, one per envelope', function () {
    $agent = ($this->makeAgent)();

    $agent->receive(($this->line)([['type' => 'query', 'sql' => 'select 1']]));
    $agent->receive(($this->line)([['type' => 'query', 'sql' => 'select 2']]));
    $agent->receive(($this->line)([['type' => 'log', 'message' => 'hi']], 'staging'));
    $agent->flush(microtime(true));

    [$request] = $this->lumberjack->requests;
    $batches = $this->lumberjack->batches();

    expect($batches)->toHaveCount(2)
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer secret-token')
        ->and($request->getHeaderLine('Content-Encoding'))->toBe('gzip')
        ->and($batches[0]['environment'])->toBe('production')
        ->and(array_column($batches[0]['events'], 'sql'))->toBe(['select 1', 'select 2']);
});

it('spools a batch when Lumberjack is down and sends it once it is back', function () {
    $down = true;
    $this->lumberjack = new FakeLumberjack(function (RequestInterface $request) use (&$down) {
        if (str_ends_with($request->getUri()->getPath(), '/api/ingest') && $down) {
            $down = false;

            return new Response(503);
        }

        return FakeLumberjack::accept($request);
    });
    $agent = ($this->makeAgent)();
    $now = microtime(true);

    $agent->receive(($this->line)([['type' => 'log', 'message' => 'keep me']]));
    $agent->flush($now);

    expect($this->spool->count())->toBe(1)->and($agent->backingOff($now + 1))->toBeTrue();

    $agent->tick($now + 6);

    expect($this->spool->count())->toBe(0);
});

it('drops a batch the server rejects instead of retrying it forever', function () {
    $this->lumberjack = new FakeLumberjack(fn () => new Response(422, [], '{"message":"invalid"}'));
    $agent = ($this->makeAgent)();

    $agent->receive(($this->line)([['type' => 'nonsense']]));
    $agent->flush(microtime(true));

    expect($this->spool->count())->toBe(0)->and($agent->backingOff(microtime(true)))->toBeFalse();
});

it('stops when asked to terminate, without treating the message as data', function () {
    $agent = ($this->makeAgent)();

    $agent->receive((string) json_encode(['control' => 'terminate']));
    $agent->flush(microtime(true));

    expect($agent->stopRequested())->toBeTrue()->and($this->lumberjack->requests)->toBe([]);
});

it('sends a heartbeat with its host and what it is', function () {
    ($this->makeAgent)()->tick(microtime(true));

    [$heartbeat] = $this->lumberjack->heartbeats();

    expect($heartbeat['host'])->toBe('web-1')
        ->and($heartbeat['client'])->toBe(AgentInfo::describe())
        ->and($heartbeat['client']['agent']['name'])->toBe('lumberjack-agent-php')
        ->and($heartbeat['version'])->toBe(AgentInfo::version())
        ->and($heartbeat)->not->toHaveKey('name');
});

it('sends what the SDK running it says it is, and its name', function () {
    $client = ['sdk' => ['name' => 'lumberjack.php.laravel', 'version' => '1.2.3'], ...AgentInfo::describe()];

    ($this->makeAgent)('queue', client: $client)->tick(microtime(true));

    [$heartbeat] = $this->lumberjack->heartbeats();

    expect($heartbeat['client'])->toBe($client)
        ->and($heartbeat['version'])->toBe('1.2.3')
        ->and($heartbeat['name'])->toBe('queue');
});

it('sends what the status probe measures, and hands the health states it gets back on', function () {
    $this->lumberjack = new FakeLumberjack(fn (RequestInterface $request) => new Response(200, [], '{"health":{"db":"up"}}'));
    $states = null;

    ($this->makeAgent)(status: fn (): array => ['queues' => ['default' => 3]], health: function (array $health) use (&$states): void {
        $states = $health;
    })->tick(microtime(true));

    expect($this->lumberjack->heartbeats()[0]['queues'])->toBe(['default' => 3])
        ->and($states)->toBe(['db' => 'up']);
});

it('keeps sending heartbeats when a probe or the health store fails', function () {
    $agent = ($this->makeAgent)(status: fn () => throw new RuntimeException('queue down'), health: fn () => throw new RuntimeException('cache down'));

    $agent->tick(microtime(true));

    expect($this->lumberjack->heartbeats())->toHaveCount(1);
});

it('turns away data from an app of another project, and warns about it once', function () {
    $warnings = [];
    $own = ProjectFingerprint::of('secret-token');
    $agent = ($this->makeAgent)(null, $own, function (string $message) use (&$warnings): void {
        $warnings[] = $message;
    });
    $line = fn (?string $project, string $message): string => (string) json_encode(['v' => 1, 'sdk' => 'test', 'environment' => 'production', 'host' => 'web-1', 'project' => $project, 'events' => [['type' => 'log', 'message' => $message]]]);

    $agent->receive($line($own, 'mine'));
    $agent->receive($line(ProjectFingerprint::of('other-token'), 'theirs'));
    $agent->receive($line(ProjectFingerprint::of('other-token'), 'theirs again'));
    $agent->receive($line(null, 'unmarked, from an older SDK'));
    $agent->flush(microtime(true));

    $sent = array_merge(...array_map(fn (array $batch): array => array_column($batch['events'], 'message'), $this->lumberjack->batches()));

    expect($sent)->toEqualCanonicalizing(['mine', 'unmarked, from an older SDK'])
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain((string) ProjectFingerprint::of('other-token'));
});

it('never sends the mark on to Lumberjack', function () {
    $agent = ($this->makeAgent)(null, ProjectFingerprint::of('secret-token'));

    $agent->receive((string) json_encode(['v' => 1, 'project' => ProjectFingerprint::of('secret-token'), 'events' => [['type' => 'log', 'message' => 'x']]]));
    $agent->flush(microtime(true));

    expect($this->lumberjack->batches()[0])->not->toHaveKey('project');
});

it('drains the spool a little per tick, so it goes back to accepting connections', function () {
    $spool = new Spool($this->spoolPath, 10_000_000);

    foreach (range(1, 10) as $i) {
        $spool->push((string) gzencode('{"events":[]}'));
    }

    $this->lumberjack = new FakeLumberjack(function (RequestInterface $request) {
        usleep(100_000);

        return FakeLumberjack::accept($request);
    });
    $agent = new Agent(($this->sender)(), $spool, 5, 'web-1');

    $start = microtime(true);
    $agent->tick(microtime(true));

    expect(microtime(true) - $start)->toBeLessThan(0.6)
        ->and($spool->count())->toBeGreaterThanOrEqual(5);
});

it('sends request counts in batches of their own, so a server that refuses them keeps the rest', function () {
    $this->lumberjack = new FakeLumberjack(function (RequestInterface $request) {
        $events = json_decode((string) gzdecode((string) $request->getBody()), true)['events'] ?? [];

        return in_array('request_counts', array_column($events, 'type'), true) ? new Response(422) : FakeLumberjack::accept($request);
    });
    $agent = ($this->makeAgent)();

    $agent->receive(($this->line)([['type' => 'request', 'route' => '/'], ['type' => 'request_counts', 'counts' => []]]));
    $agent->flush(microtime(true));

    expect(array_map(fn (array $batch): array => array_column($batch['events'], 'type'), $this->lumberjack->batches()))->toBe([['request'], ['request_counts']])
        ->and($this->spool->count())->toBe(0);
});

describe('the agent key', function () {
    beforeEach(function () {
        $this->warnings = [];
        $this->key = ProjectFingerprint::agentKey('secret-token');
        $this->keyed = ($this->makeAgent)(null, ProjectFingerprint::of('secret-token'), function (string $message): void {
            $this->warnings[] = $message;
        }, key: $this->key);
    });

    it('turns away data and commands without it, and warns once', function () {
        $this->keyed->receive((string) json_encode(['events' => [['type' => 'exception', 'message' => 'forged']]]));
        $this->keyed->receive((string) json_encode(['key' => 'guess', 'events' => [['type' => 'exception']]]));
        $this->keyed->receive((string) json_encode(['control' => 'terminate']));
        $this->keyed->flush(microtime(true));

        expect($this->keyed->stopRequested())->toBeFalse()
            ->and($this->warnings)->toHaveCount(1)->and($this->warnings[0])->toContain('without the agent key')
            ->and($this->lumberjack->requests)->toBe([]);
    });

    it('takes data and commands with it', function () {
        $this->keyed->receive((string) json_encode(['key' => $this->key, 'events' => [['type' => 'log']]]));
        $this->keyed->flush(microtime(true));
        $this->keyed->receive((string) json_encode(['key' => $this->key, 'control' => 'terminate']));

        expect($this->keyed->stopRequested())->toBeTrue()
            ->and($this->lumberjack->requests)->toHaveCount(1);
    });

    it('is derived from the token unless one is given, and is never the token', function () {
        expect($this->key)->not->toContain('secret-token')
            ->and(ProjectFingerprint::agentKey('secret-token', 'explicit'))->toBe('explicit')
            ->and(ProjectFingerprint::agentKey(null))->toBeNull();
    });
});

<?php

declare(strict_types=1);

use ExeQue\LumberjackAgent\AgentClient;

it('hands a payload to a listening agent over TCP as one JSON line', function () {
    $port = freePort();
    $server = stream_socket_server("tcp://127.0.0.1:{$port}");

    expect((new AgentClient('127.0.0.1', $port))->send(['v' => 1, 'events' => [['type' => 'log']]]))->toBeTrue();

    expect(json_decode((string) fgets(stream_socket_accept($server, 1)), true))->toBe(['v' => 1, 'events' => [['type' => 'log']]]);
});

it('gives up quietly when no agent is listening', function () {
    $start = microtime(true);

    expect((new AgentClient('127.0.0.1', freePort()))->send(['v' => 1, 'events' => []]))->toBeFalse()
        ->and(microtime(true) - $start)->toBeLessThan(0.5);
});

it('stops trying a missing agent for a while, and reports what it dropped once the agent is back', function () {
    $port = freePort();
    $client = new AgentClient('127.0.0.1', $port, breakSeconds: 0.2);

    expect($client->send(['v' => 1, 'events' => []]))->toBeFalse();

    // The agent comes back, but the breaker is still open: nothing connects.
    $server = stream_socket_server("tcp://127.0.0.1:{$port}");
    expect($client->send(['v' => 1, 'events' => []]))->toBeFalse()
        ->and(@stream_socket_accept($server, 0.05))->toBeFalse();

    usleep(250_000);
    expect($client->send(['v' => 1, 'events' => [['type' => 'query']]]))->toBeTrue();

    $events = json_decode((string) fgets(stream_socket_accept($server, 1)), true)['events'];
    expect($events)->toHaveCount(2)
        ->and($events[1])->toMatchArray(['type' => 'log', 'level' => 'warning', 'message' => 'Lumberjack dropped 2 batches while the agent could not be reached.']);
});

it('puts the agent key on every line', function () {
    $port = freePort();
    $server = stream_socket_server("tcp://127.0.0.1:{$port}");

    (new AgentClient('127.0.0.1', $port, key: 'the-key'))->send(['control' => 'terminate']);

    expect(json_decode((string) fgets(stream_socket_accept($server, 1)), true))->toBe(['control' => 'terminate', 'key' => 'the-key']);
});

it('splits a batch that is too long for one line', function () {
    $client = new class ('127.0.0.1', 1) extends AgentClient {
        /** @var list<string> */
        public array $lines = [];

        protected function write(string $line): bool
        {
            $this->lines[] = $line;

            return true;
        }
    };
    $event = ['type' => 'log', 'message' => str_repeat('x', 10_000)];

    expect($client->send(['v' => 1, 'events' => array_fill(0, 1000, $event)]))->toBeTrue()
        ->and(count($client->lines))->toBeGreaterThan(1)
        ->and(max(array_map(strlen(...), $client->lines)))->toBeLessThanOrEqual(AgentClient::MAX_LINE)
        ->and(array_sum(array_map(fn (string $line): int => count(json_decode($line, true)['events']), $client->lines)))->toBe(1000);
});

describe('the agent host', function () {
    it('sends to a host name', function () {
        $port = freePort();
        $server = stream_socket_server("tcp://127.0.0.1:{$port}");
        $client = new AgentClient('localhost', $port);

        expect($client->send(['events' => []]))->toBeTrue()
            ->and($client->send(['events' => []]))->toBeTrue();
    });

    it('does not look up a name that failed again for a while', function () {
        $client = new AgentClient('lumberjack-agent.invalid', 2407);
        $client->send(['events' => []]);

        $start = microtime(true);
        expect($client->send(['events' => []]))->toBeFalse()
            ->and(microtime(true) - $start)->toBeLessThan(0.002);
    });
});

describe('while the agent is away', function () {
    beforeEach(function () {
        $this->path = sys_get_temp_dir().'/lumberjack-client-spool-'.bin2hex(random_bytes(4));
        $this->port = freePort();
        $this->client = new AgentClient('127.0.0.1', $this->port, breakSeconds: 0.05, spoolPath: $this->path);
        $this->payload = fn (string $message): array => ['v' => 1, 'events' => [['type' => 'log', 'message' => $message]]];
        $this->files = fn (string $pattern = '*.json.gz'): array => glob($this->path.'/'.$pattern) ?: [];
        $this->lines = function ($server, int $count): array {
            $lines = [];

            for ($i = 0; $i < $count; $i++) {
                $lines[] = json_decode((string) fgets(stream_socket_accept($server, 1)), true)['events'][0]['message'];
            }

            return $lines;
        };
    });

    afterEach(fn () => removeDirectory($this->path));

    it('keeps batches, also behind the breaker, and sends them oldest first once it is back', function () {
        $this->client->send(($this->payload)('one'));
        $this->client->send(($this->payload)('two'));
        expect(($this->files)())->toHaveCount(2);

        $server = stream_socket_server("tcp://127.0.0.1:{$this->port}");
        usleep(60_000);
        expect($this->client->send(($this->payload)('now')))->toBeTrue();

        expect(($this->lines)($server, 3))->toBe(['now', 'one', 'two'])
            ->and(($this->files)())->toBe([])
            ->and(($this->files)('*.sending'))->toBe([]);
    });

    it('does not report kept batches as dropped', function () {
        $this->client->send(($this->payload)('one'));
        $server = stream_socket_server("tcp://127.0.0.1:{$this->port}");
        usleep(60_000);
        $this->client->send(['v' => 1, 'events' => [['type' => 'query']]]);

        expect(json_decode((string) fgets(stream_socket_accept($server, 1)), true)['events'])->toHaveCount(1);
    });

    it('sends the key with a kept batch, without storing it', function () {
        $client = new AgentClient('127.0.0.1', $this->port, breakSeconds: 0.05, key: 'secret-key', spoolPath: $this->path);
        $client->send(($this->payload)('one'));
        expect(gzdecode((string) file_get_contents(($this->files)()[0])))->not->toContain('secret-key');

        $server = stream_socket_server("tcp://127.0.0.1:{$this->port}");
        usleep(60_000);
        $client->send(($this->payload)('now'));
        fgets(stream_socket_accept($server, 1));

        expect(json_decode((string) fgets(stream_socket_accept($server, 1)), true)['key'])->toBe('secret-key');
    });

    it('sends only a few per send, and leaves the rest for the next', function () {
        for ($i = 0; $i < AgentClient::REPLAY_PER_SEND + 2; $i++) {
            usleep(60_000);
            $this->client->send(($this->payload)("old {$i}"));
        }

        $server = stream_socket_server("tcp://127.0.0.1:{$this->port}");
        usleep(60_000);
        $this->client->send(($this->payload)('now'));
        ($this->lines)($server, AgentClient::REPLAY_PER_SEND + 1);

        expect(($this->files)())->toHaveCount(2);
    });

    it('throws away a batch that has waited too long', function () {
        $this->client->send(($this->payload)('stale'));
        touch(($this->files)()[0], time() - AgentClient::SPOOL_MAX_AGE - 1);

        $server = stream_socket_server("tcp://127.0.0.1:{$this->port}");
        usleep(60_000);
        $this->client->send(($this->payload)('now'));

        expect(($this->lines)($server, 1))->toBe(['now'])
            ->and(@stream_socket_accept($server, 0.05))->toBeFalse()
            ->and(($this->files)())->toBe([]);
    });

    it('never keeps a control message', function () {
        $this->client->send(['control' => 'terminate']);

        expect(($this->files)())->toBe([]);
    });
});

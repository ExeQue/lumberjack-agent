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

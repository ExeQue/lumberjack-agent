<?php

declare(strict_types=1);

use ExeQue\LumberjackAgent\AgentClient;
use ExeQue\LumberjackAgent\Cli;
use ExeQue\LumberjackAgent\ProjectFingerprint;

beforeEach(function () {
    $this->output = [];
    $this->cli = fn (array $env): Cli => new Cli($env, function (string $line): void {
        $this->output[] = $line;
    });
});

it('explains its settings', function () {
    expect(($this->cli)([])->run(['--help']))->toBe(0)
        ->and($this->output[0])->toContain('LUMBERJACK_URL')->toContain('LUMBERJACK_AGENT_PORT');
});

it('needs the address and the token', function () {
    expect(($this->cli)(['LUMBERJACK_URL' => 'https://lumberjack.test'])->run())->toBe(1)
        ->and($this->output[0])->toContain('Set LUMBERJACK_URL and LUMBERJACK_TOKEN');
});

it('says so when its port is taken', function () {
    $port = freePort();
    $taken = stream_socket_server("tcp://127.0.0.1:{$port}");

    expect(($this->cli)(['LUMBERJACK_URL' => 'https://lumberjack.test', 'LUMBERJACK_TOKEN' => 't', 'LUMBERJACK_AGENT_PORT' => (string) $port])->run())->toBe(1)
        ->and(end($this->output))->toContain("Cannot listen on 127.0.0.1:{$port}");
});

it('runs as a process: takes batches from apps, spools them while Lumberjack is away, and stops when told', function () {
    $port = freePort();
    $spool = sys_get_temp_dir().'/lumberjack-spool-'.bin2hex(random_bytes(4));
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/bin/lumberjack-agent'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: [
        'LUMBERJACK_URL' => 'http://127.0.0.1:'.freePort(),
        'LUMBERJACK_TOKEN' => 'secret-token',
        'LUMBERJACK_AGENT_PORT' => (string) $port,
        'LUMBERJACK_AGENT_SPOOL_PATH' => $spool,
    ]);
    $client = new AgentClient('127.0.0.1', $port, breakSeconds: 0.05, key: ProjectFingerprint::agentKey('secret-token'));

    $deadline = microtime(true) + 10;
    while (! $client->send(['v' => 1, 'environment' => 'production', 'events' => [['type' => 'log', 'message' => 'from the app']]]) && microtime(true) < $deadline) {
        usleep(100_000);
    }
    $client->send(['control' => 'terminate']);

    while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
        usleep(50_000);
    }
    $running = proc_get_status($process)['running'];
    proc_terminate($process);
    $log = stream_get_contents($pipes[2]);

    $batches = array_map(fn (string $file): array => json_decode((string) gzdecode((string) file_get_contents($file)), true), (array) glob($spool.'/*.json.gz'));
    removeDirectory($spool);

    expect($running)->toBeFalse()
        ->and($log)->toContain("listening on 127.0.0.1:{$port}")->toContain('Asked to terminate')
        ->and(array_column(array_merge(...array_column($batches, 'events')), 'message'))->toContain('from the app');
});

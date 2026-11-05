<?php

declare(strict_types=1);

namespace ExeQue\LumberjackAgent;

use Closure;
use ExeQue\LumberjackAgent\Exceptions\CannotListenException;

/**
 * Listens on a local port for newline-delimited batches from the apps on this server and
 * hands them to the agent, which sends them on to Lumberjack. One thread: while the agent
 * sends, apps' connections wait in the listen backlog.
 */
class Server
{
    /** The longest line a client may send; the app splits a batch that would be longer. */
    public const int MAX_CLIENT_BUFFER = AgentClient::MAX_LINE;

    /** Connections open at once; more wait in the backlog. */
    public const int MAX_CLIENTS = 256;

    /** Seconds a connection may stay open without sending. */
    public const int IDLE_SECONDS = 10;

    public const int BACKLOG = 1024;

    private bool $running = true;

    /**
     * @param  int  $memoryLimit  MB; past it the server sends what it has and returns, so a process manager starts a fresh one. 0 = no limit
     * @param  (Closure(string): void)|null  $log  told why the server stops
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 2407,
        private readonly int $memoryLimit = 128,
        private readonly ?Closure $log = null,
    ) {
    }

    /**
     * Runs until stop() is called (e.g. from a signal handler), the agent is asked to
     * terminate or memory passes the limit; then sends what is left.
     *
     * @throws CannotListenException when the port cannot be opened
     */
    public function run(): void
    {
        // A deep backlog: while the agent sends, apps still connect and write into the
        // kernel's buffer instead of timing out.
        $server = @stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $error, context: stream_context_create(['socket' => ['backlog' => self::BACKLOG]]));

        if ($server === false) {
            throw CannotListenException::on($this->host, $this->port, $error);
        }

        stream_set_blocking($server, false);

        try {
            $this->loop($server);
        } finally {
            $this->agent->flush(microtime(true));
            fclose($server);
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * @param  resource  $server
     */
    private function loop($server): void
    {
        /** @var array<int, resource> $clients */
        $clients = [];
        /** @var array<int, string> $buffers */
        $buffers = [];
        /** @var array<int, float> $seen */
        $seen = [];

        while ($this->running) {
            // At the cap, new connections wait in the backlog until one closes.
            $read = count($clients) < self::MAX_CLIENTS ? [$server, ...array_values($clients)] : array_values($clients);
            $write = $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                $read = [];
            }

            foreach ($read as $stream) {
                if ($stream === $server) {
                    $client = @stream_socket_accept($server, 0);

                    if ($client !== false) {
                        stream_set_blocking($client, false);
                        $clients[(int) $client] = $client;
                        $buffers[(int) $client] = '';
                        $seen[(int) $client] = microtime(true);
                    }

                    continue;
                }

                $id = (int) $stream;
                $chunk = fread($stream, 65536);

                if ($chunk !== false && $chunk !== '') {
                    $seen[$id] = microtime(true);
                    $buffers[$id] .= $chunk;

                    while (($pos = strpos($buffers[$id], "\n")) !== false) {
                        $this->agent->receive(substr($buffers[$id], 0, $pos));
                        $buffers[$id] = substr($buffers[$id], $pos + 1);
                    }
                }

                if (feof($stream) || strlen($buffers[$id]) > self::MAX_CLIENT_BUFFER) {
                    fclose($stream);
                    unset($clients[$id], $buffers[$id], $seen[$id]);
                }
            }

            // A connection that sends nothing holds a slot; it gets a few seconds.
            foreach ($seen as $id => $at) {
                if (microtime(true) - $at > self::IDLE_SECONDS) {
                    fclose($clients[$id]);
                    unset($clients[$id], $buffers[$id], $seen[$id]);
                }
            }

            $this->agent->tick(microtime(true));

            if ($this->agent->stopRequested()) {
                ($this->log)?->__invoke('Asked to terminate; sending what is left and exiting.');
                $this->running = false;
            }

            if ($this->memoryLimit > 0 && memory_get_usage(true) > $this->memoryLimit * 1024 * 1024) {
                ($this->log)?->__invoke("Memory passed {$this->memoryLimit} MB; sending what is left and exiting.");
                $this->running = false;
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }
}

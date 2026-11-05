<?php

declare(strict_types=1);

namespace ExeQue\LumberjackAgent;

use Closure;
use ExeQue\LumberjackAgent\Exceptions\CannotListenException;

/**
 * The standalone agent, configured from environment variables: bin/lumberjack-agent.
 * An SDK that runs the agent itself (such as laravel-lumberjack) builds the Server instead.
 */
final class Cli
{
    public const string HELP = <<<'TXT'
        Usage: lumberjack-agent

        Listens on a local port for batches from your apps and sends them on to Lumberjack.
        Run it under Supervisor or systemd. Configured with environment variables:

          LUMBERJACK_URL                 Lumberjack's address (required)
          LUMBERJACK_TOKEN               the project's server token (required)
          LUMBERJACK_ENABLED             false: send nothing and wait to be stopped
          LUMBERJACK_ENVIRONMENT         the environment health states are read for
          LUMBERJACK_AGENT_HOST          address to listen on (127.0.0.1)
          LUMBERJACK_AGENT_PORT          port to listen on (2407)
          LUMBERJACK_AGENT_KEY           the key apps put on every line (derived from the token)
          LUMBERJACK_AGENT_NAME          what Lumberjack lists this agent as (the host name)
          LUMBERJACK_AGENT_INTERVAL      seconds between batches (5)
          LUMBERJACK_AGENT_SEND_TIMEOUT  seconds one send may take (5)
          LUMBERJACK_AGENT_SPOOL_PATH    where failed batches wait (a folder in the temp dir)
          LUMBERJACK_AGENT_SPOOL_MAX_MB  how much they may take (100)
          LUMBERJACK_AGENT_MEMORY        exit past this many MB, for a fresh start (128, 0 = no limit)
          LUMBERJACK_AGENT_DISK          the path whose disk the heartbeat reports (the working directory)

        TXT;

    private ?Server $server = null;

    private bool $running = true;

    /**
     * @param  array<string, string>  $env
     * @param  Closure(string): void  $out
     */
    public function __construct(
        private readonly array $env,
        private readonly Closure $out,
    ) {
    }

    /**
     * @param  list<string>  $arguments
     */
    public function run(array $arguments = []): int
    {
        if (array_intersect($arguments, ['-h', '--help']) !== []) {
            ($this->out)(self::HELP);

            return 0;
        }

        $this->trapSignals();

        if (in_array(strtolower($this->get('LUMBERJACK_ENABLED') ?? 'true'), ['false', '0', 'off', 'no'], true)) {
            return $this->idle();
        }

        $url = $this->get('LUMBERJACK_URL');
        $token = $this->get('LUMBERJACK_TOKEN');

        if ($url === null || $token === null) {
            ($this->out)('Set LUMBERJACK_URL and LUMBERJACK_TOKEN first. See lumberjack-agent --help.');

            return 1;
        }

        $host = $this->get('LUMBERJACK_AGENT_HOST') ?? '127.0.0.1';
        $port = $this->int('LUMBERJACK_AGENT_PORT', 2407);
        $name = $this->get('LUMBERJACK_AGENT_NAME');
        $environment = $this->get('LUMBERJACK_ENVIRONMENT');
        $metrics = new HostMetrics($this->get('LUMBERJACK_AGENT_DISK') ?? (getcwd() ?: '/'));
        $warn = fn (string $message) => ($this->out)($message);

        $agent = new Agent(
            new Sender($url, $token, max(1, $this->int('LUMBERJACK_AGENT_SEND_TIMEOUT', Sender::TIMEOUT))),
            new Spool(
                $this->get('LUMBERJACK_AGENT_SPOOL_PATH') ?? sys_get_temp_dir()."/lumberjack-agent-spool-{$port}",
                $this->int('LUMBERJACK_AGENT_SPOOL_MAX_MB', 100) * 1024 * 1024,
                $warn,
            ),
            max(1, $this->int('LUMBERJACK_AGENT_INTERVAL', 5)),
            gethostname() ?: null,
            $name,
            ProjectFingerprint::of($token),
            $warn,
            fn (): array => array_filter(['environment' => $environment, 'metrics' => $metrics->measure()], fn (mixed $value): bool => $value !== null),
            key: ProjectFingerprint::agentKey($token, $this->get('LUMBERJACK_AGENT_KEY')),
        );

        $this->server = new Server($agent, $host, $port, $this->int('LUMBERJACK_AGENT_MEMORY', 128), $warn);

        ($this->out)(($name !== null ? "Lumberjack agent {$name}" : 'Lumberjack agent')." listening on {$host}:{$port}, sending to {$url}.");

        try {
            $this->server->run();
        } catch (CannotListenException $exception) {
            ($this->out)($exception->getMessage());

            return 1;
        }

        return 0;
    }

    public function stop(): void
    {
        $this->running = false;
        $this->server?->stop();
    }

    /**
     * Turned off: nothing to send, but a process manager would restart an agent that
     * exits over and over, so it waits to be stopped.
     */
    private function idle(): int
    {
        ($this->out)('Lumberjack is disabled (LUMBERJACK_ENABLED=false); the agent sends nothing and waits to be stopped.');

        while ($this->running) {
            sleep(1);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        return 0;
    }

    private function trapSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, fn () => $this->stop());
        }
    }

    private function get(string $name): ?string
    {
        $value = trim($this->env[$name] ?? '');

        return $value === '' ? null : $value;
    }

    private function int(string $name, int $default): int
    {
        $value = $this->get($name);

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }
}

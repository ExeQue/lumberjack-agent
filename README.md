<p align="center">
<a href="https://packagist.org/packages/exeque/lumberjack-agent"><img src="https://img.shields.io/packagist/v/exeque/lumberjack-agent" alt="Latest version"></a>
<a href="https://packagist.org/packages/exeque/lumberjack-agent"><img src="https://img.shields.io/packagist/dt/exeque/lumberjack-agent" alt="Total downloads"></a>
<a href="https://github.com/ExeQue/lumberjack-agent/actions/workflows/tests.yml"><img src="https://github.com/ExeQue/lumberjack-agent/actions/workflows/tests.yml/badge.svg?branch=main" alt="Tests"></a>
<img src="https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2FExeQue%2Flumberjack-agent%2Fmain%2Fcomposer.json&query=%24.require.php&label=php&color=777bb4" alt="PHP version">
<a href="https://github.com/ExeQue/lumberjack-agent/blob/main/LICENSE"><img src="https://img.shields.io/github/license/ExeQue/lumberjack-agent" alt="License"></a>
</p>

# Lumberjack agent

The local agent between your apps and [Lumberjack](https://exeque.github.io/lumberjack/). Apps
hand it batches over a local TCP port in a few microseconds; it sends them on to Lumberjack every
few seconds, with retry, back-off and a disk spool, so a slow or unreachable Lumberjack never slows
an app down. Once a minute it sends a heartbeat with how busy the host is.

The [Laravel SDK](https://exeque.github.io/laravel-lumberjack/) runs it as
`php artisan lumberjack:agent`. Anything else, in PHP or another language, can run it on its own.

## Install

```bash
composer require exeque/lumberjack-agent
```

PHP 8.3 or newer. With `ext-pcntl` the agent sends what it holds before it stops on `SIGTERM`.

## Run it

```bash
LUMBERJACK_URL=https://lumberjack.example.com \
LUMBERJACK_TOKEN=lj_srv_... \
vendor/bin/lumberjack-agent
```

Run it under Supervisor or systemd, one agent per project and server. `vendor/bin/lumberjack-agent --help`
lists every setting:

| Variable                        | Default                     |                                                 |
| ------------------------------- | --------------------------- | ----------------------------------------------- |
| `LUMBERJACK_URL`                | (required)                  | Lumberjack's address                            |
| `LUMBERJACK_TOKEN`              | (required)                  | The project's server token                      |
| `LUMBERJACK_ENABLED`            | `true`                      | `false`: send nothing and wait to be stopped    |
| `LUMBERJACK_ENVIRONMENT`        |                             | The environment health states are read for      |
| `LUMBERJACK_AGENT_HOST`         | `127.0.0.1`                 | Address to listen on                            |
| `LUMBERJACK_AGENT_PORT`         | `2407`                      | Port to listen on                               |
| `LUMBERJACK_AGENT_KEY`          | derived from the token      | The key apps put on every line                  |
| `LUMBERJACK_AGENT_NAME`         | the host name               | What Lumberjack lists this agent as             |
| `LUMBERJACK_AGENT_INTERVAL`     | `5`                         | Seconds between batches                         |
| `LUMBERJACK_AGENT_SEND_TIMEOUT` | `5`                         | Seconds one send may take                       |
| `LUMBERJACK_AGENT_SPOOL_PATH`   | a folder in the temp dir    | Where failed batches wait                       |
| `LUMBERJACK_AGENT_SPOOL_MAX_MB` | `100`                       | How much they may take; the oldest go first     |
| `LUMBERJACK_AGENT_MEMORY`       | `128`                       | Exit past this many MB, for a fresh start       |
| `LUMBERJACK_AGENT_DISK`         | the working directory       | The path whose disk the heartbeat reports       |

## Send to it

From PHP:

```php
use ExeQue\LumberjackAgent\AgentClient;
use ExeQue\LumberjackAgent\ProjectFingerprint;

$agent = new AgentClient('127.0.0.1', 2407, key: ProjectFingerprint::agentKey($token));

$agent->send([
    'v' => 1,
    'sdk' => ['name' => 'lumberjack.php.mine', 'version' => '1.0.0'],
    'environment' => 'production',
    'release' => 'a1b2c3d',
    'project' => ProjectFingerprint::of($token),
    'events' => [['type' => 'log', 'level' => 'error', 'message' => 'Payment failed']],
]);
```

`send()` never throws and never blocks for long: when the agent is not there, the batch is
dropped, and the client stops trying for a few seconds.

From any other language, open a TCP connection to the agent and write one JSON object per line:

- `key`: the agent key. `LUMBERJACK_AGENT_KEY` when set, else the HMAC-SHA256 of
  `lumberjack-agent` with the server token as key, hex. Lines without it are turned away.
- `project`: optional, the first 12 hex characters of the SHA-256 of the server token. An agent
  turns away lines of another project, so an app pointed at the wrong port says so.
- `sdk`, `environment`, `release`, `host`: the envelope Lumberjack files the events under.
- `events`: the events, as Lumberjack's [ingest API](https://exeque.github.io/lumberjack/docs/api)
  describes them.
- `{"key": "...", "control": "terminate"}` asks the agent to send what it holds and exit, e.g.
  after a deploy.

The heartbeat tells the agent which event types the server knows. An event of a type it does not know is dropped, and once an hour per type the agent reports an `UnsupportedFeature` exception, so the project gets an issue saying to update the server.

A line may be 4 MB at most; split a larger batch.

## Develop

```bash
composer install
composer test       # Pest
composer analyse    # PHPStan, level 7
composer lint       # Pint
```

See [Contributing](CONTRIBUTING.md) and [Security](SECURITY.md). MIT licensed.

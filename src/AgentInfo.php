<?php

declare(strict_types=1);

namespace ExeQue\LumberjackAgent;

use Composer\InstalledVersions;

/**
 * What this agent is, as it tells Lumberjack in its heartbeat. An SDK that runs the agent
 * adds itself and its framework; the ids stay put when packages are renamed.
 */
final class AgentInfo
{
    public const string NAME = 'lumberjack-agent-php';

    public const string PACKAGE = 'exeque/lumberjack-agent';

    private static ?string $version = null;

    /**
     * The installed version as Composer has it: "0.2.0" for a tag, "dev-main" for a
     * branch, "dev" when Composer cannot tell.
     */
    public static function version(): string
    {
        return self::$version ??= ltrim((class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)
            ? InstalledVersions::getPrettyVersion(self::PACKAGE)
            : null) ?? 'dev', 'v');
    }

    /**
     * @return array<string, array{name: string, version: string}>
     */
    public static function describe(): array
    {
        return [
            'agent' => ['name' => self::NAME, 'version' => self::version()],
            'runtime' => ['name' => 'php', 'version' => PHP_VERSION],
        ];
    }
}

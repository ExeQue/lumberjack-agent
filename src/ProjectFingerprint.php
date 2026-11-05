<?php

declare(strict_types=1);

namespace ExeQue\LumberjackAgent;

/**
 * A short, one-way mark of a server token. An app puts it on what it hands the
 * agent, so an agent can tell data meant for another project from its own
 * without the token itself travelling to it.
 */
final class ProjectFingerprint
{
    public static function of(?string $token): ?string
    {
        return $token === null || $token === '' ? null : substr(hash('sha256', $token), 0, 12);
    }

    /**
     * The secret an app puts on every line it hands the agent, so the agent takes data and
     * commands only from apps that know it: LUMBERJACK_AGENT_KEY, else derived from the
     * server token both share. Never the token itself.
     */
    public static function agentKey(?string $token, ?string $key = null): ?string
    {
        if ($key !== null && $key !== '') {
            return $key;
        }

        return $token === null || $token === '' ? null : hash_hmac('sha256', 'lumberjack-agent', $token);
    }
}

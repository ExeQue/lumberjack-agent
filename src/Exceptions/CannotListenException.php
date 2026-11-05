<?php

declare(strict_types=1);

namespace ExeQue\LumberjackAgent\Exceptions;

use RuntimeException;

class CannotListenException extends RuntimeException implements AgentExceptionInterface
{
    public static function on(string $host, int $port, string $reason): self
    {
        return new self("Cannot listen on {$host}:{$port}: {$reason}");
    }
}

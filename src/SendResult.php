<?php

declare(strict_types=1);

namespace ExeQue\LumberjackAgent;

enum SendResult
{
    case Sent;
    case Retry;
    case Rejected;
}

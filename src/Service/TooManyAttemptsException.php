<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class TooManyAttemptsException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct('Too many attempts.');
    }

    public function minutes(): int
    {
        return (int) ceil($this->retryAfterSeconds / 60);
    }
}

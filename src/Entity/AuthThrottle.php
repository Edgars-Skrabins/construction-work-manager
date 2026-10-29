<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;

final readonly class AuthThrottle
{
    public function __construct(
        public int $failures,
        public DateTimeImmutable $windowStart,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Shared;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

final readonly class Clock implements ClockInterface
{
    public function __construct(
        private string $timezone,
    ) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone($this->timezone));
    }
}

<?php

declare(strict_types=1);

namespace App\Shared;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

final readonly class Clock implements ClockInterface
{
    private DateTimeZone $timezone;

    public function __construct(string $timezone)
    {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }
}

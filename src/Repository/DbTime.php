<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use DateTimeZone;

final class DbTime
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /**
     * @return ($time is null ? null : string)
     */
    public static function toUtc(?DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    /**
     * @return ($value is null ? null : DateTimeImmutable)
     */
    public static function fromUtc(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

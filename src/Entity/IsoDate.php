<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;

final class IsoDate
{
    public const string FORMAT = 'Y-m-d';

    public static function parse(string $value, ?DateTimeZone $timezone = null): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, $timezone);

        return $date !== false && $date->format(self::FORMAT) === $value ? $date : null;
    }
}

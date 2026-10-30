<?php

declare(strict_types=1);

namespace App\Entity;

final class AssignmentDescription
{
    public const int MAX_LENGTH = 1000;

    public static function normalize(string $description): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $description));
    }

    public static function isTooLong(string $description): bool
    {
        return strlen(mb_convert_encoding($description, 'UTF-16LE', 'UTF-8')) / 2 > self::MAX_LENGTH;
    }
}

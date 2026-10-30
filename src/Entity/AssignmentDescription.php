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
        return mb_strlen($description) > self::MAX_LENGTH;
    }
}

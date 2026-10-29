<?php

declare(strict_types=1);

namespace App\Entity;

final class SiteCode
{
    public const string PATTERN = '/^[A-Z0-9][A-Z0-9-]{2,29}$/D';

    public const string FORM_MESSAGE = 'Enter 3 to 30 letters, digits or hyphens, starting with a letter or digit.';

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }
}

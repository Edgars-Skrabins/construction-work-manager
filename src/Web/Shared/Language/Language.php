<?php

declare(strict_types=1);

namespace App\Web\Shared\Language;

enum Language: string
{
    case English = 'en';
    case Latvian = 'lv';
    case Lithuanian = 'lt';
    case Estonian = 'et';
    case Russian = 'ru';
    case Finnish = 'fi';
    case Swedish = 'sv';

    public function nativeName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Latvian => 'Latviešu',
            self::Lithuanian => 'Lietuvių',
            self::Estonian => 'Eesti',
            self::Russian => 'Русский',
            self::Finnish => 'Suomi',
            self::Swedish => 'Svenska',
        };
    }

    public static function fromCode(mixed $code): self
    {
        return is_string($code) ? self::tryFrom($code) ?? self::English : self::English;
    }
}

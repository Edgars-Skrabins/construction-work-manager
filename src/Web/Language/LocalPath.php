<?php

declare(strict_types=1);

namespace App\Web\Language;

final class LocalPath
{
    public static function isLocal(string $url): bool
    {
        if (!str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\')) {
            return false;
        }

        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        return $parts !== false && !isset($parts['scheme']) && !isset($parts['host']);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

final class SetupCodeGenerator
{
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const int LENGTH = 10;

    public function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    public function format(string $code): string
    {
        return substr($code, 0, 5) . '-' . substr($code, 5);
    }

    public function normalize(string $input): string
    {
        return strtoupper(str_replace([' ', '-'], '', $input));
    }
}

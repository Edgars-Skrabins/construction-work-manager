<?php

declare(strict_types=1);

namespace App\Web\Shared\Form;

final class FormBody
{
    public static function string(mixed $body, string $name, bool $trim = true): string
    {
        $value = is_array($body) && is_string($body[$name] ?? null) ? $body[$name] : '';

        return $trim ? trim($value) : $value;
    }
}

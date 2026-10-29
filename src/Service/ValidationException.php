<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /**
     * @param non-empty-array<string, string> $errors
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct(implode(' ', $errors));
    }

    public static function forField(string $field, string $message): self
    {
        return new self([$field => $message]);
    }
}

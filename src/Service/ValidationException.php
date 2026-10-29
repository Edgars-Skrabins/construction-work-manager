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

    /**
     * @param array<string, list<string>> $errors
     * @return array<string, list<string>>
     */
    public function mergeInto(array $errors): array
    {
        foreach ($this->errors as $field => $message) {
            $errors[$field][] = $message;
        }

        return $errors;
    }
}

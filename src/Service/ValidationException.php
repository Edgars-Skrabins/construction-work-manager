<?php

declare(strict_types=1);

namespace App\Service;

use Closure;
use RuntimeException;
use Yiisoft\Db\Exception\IntegrityException;

final class ValidationException extends RuntimeException
{
    private const array UNIQUE_VIOLATIONS = [2601, 2627, '2601', '2627'];

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
     * @template T
     * @param Closure(): T $write
     * @return T
     */
    public static function guardUnique(Closure $write, string $constraint, string $field, string $message): mixed
    {
        try {
            return $write();
        } catch (IntegrityException $e) {
            if (!in_array($e->errorInfo[1] ?? null, self::UNIQUE_VIOLATIONS, true) || !str_contains($e->getMessage(), $constraint)) {
                throw $e;
            }

            throw self::forField($field, $message);
        }
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

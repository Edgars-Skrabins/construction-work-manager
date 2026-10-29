<?php

declare(strict_types=1);

namespace App\Service;

use Closure;
use RuntimeException;
use Yiisoft\Db\Exception\IntegrityException;

final class InUseException extends RuntimeException
{
    private const int CONSTRAINT_CONFLICT = 547;

    /**
     * @param Closure(): void $delete
     */
    public static function guard(Closure $delete, string $message): void
    {
        try {
            $delete();
        } catch (IntegrityException $e) {
            if (!self::isConstraintConflict($e)) {
                throw $e;
            }

            throw new self($message, 0, $e);
        }
    }

    public static function isConstraintConflict(IntegrityException $exception): bool
    {
        return in_array($exception->errorInfo[1] ?? null, [self::CONSTRAINT_CONFLICT, (string) self::CONSTRAINT_CONFLICT], true);
    }
}

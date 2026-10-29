<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use Yiisoft\Db\Exception\IntegrityException;

final class InUseException extends RuntimeException
{
    private const int CONSTRAINT_CONFLICT = 547;

    public static function isConstraintConflict(IntegrityException $exception): bool
    {
        return in_array($exception->errorInfo[1] ?? null, [self::CONSTRAINT_CONFLICT, (string) self::CONSTRAINT_CONFLICT], true);
    }
}

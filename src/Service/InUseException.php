<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use Yiisoft\Db\Exception\IntegrityException;

final class InUseException extends RuntimeException
{
    private const int CONSTRAINT_CONFLICT = 547;

    public static function fromDelete(IntegrityException $exception, string $message): self
    {
        if (!in_array($exception->errorInfo[1] ?? null, [self::CONSTRAINT_CONFLICT, (string) self::CONSTRAINT_CONFLICT], true)) {
            throw $exception;
        }

        return new self($message, 0, $exception);
    }
}

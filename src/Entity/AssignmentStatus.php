<?php

declare(strict_types=1);

namespace App\Entity;

enum AssignmentStatus: string
{
    case Active = 'active';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }
}

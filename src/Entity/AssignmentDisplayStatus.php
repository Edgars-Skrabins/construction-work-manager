<?php

declare(strict_types=1);

namespace App\Entity;

enum AssignmentDisplayStatus
{
    case Planned;
    case InProgress;
    case Overdue;
    case Done;
    case Cancelled;

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::InProgress => 'In progress',
            self::Overdue => 'Overdue',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Planned => 'text-bg-info',
            self::InProgress => 'text-bg-primary',
            self::Overdue => 'text-bg-warning',
            self::Done => 'text-bg-success',
            self::Cancelled => 'text-bg-secondary',
        };
    }
}

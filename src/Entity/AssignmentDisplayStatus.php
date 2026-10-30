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

    /**
     * @return list<self>
     */
    public static function calendarStatuses(): array
    {
        return [self::Planned, self::InProgress, self::Overdue, self::Done];
    }

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
        return 'status-badge ' . $this->stripeClass();
    }

    public function stripeClass(): string
    {
        return match ($this) {
            self::Planned => 'status-planned',
            self::InProgress => 'status-in-progress',
            self::Overdue => 'status-overdue',
            self::Done => 'status-done',
            self::Cancelled => 'status-cancelled',
        };
    }
}

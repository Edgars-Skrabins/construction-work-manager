<?php

declare(strict_types=1);

namespace App\Web\EmployeeCalendar;

use DateTimeImmutable;

final readonly class CalendarDay
{
    /**
     * @param list<CalendarEntry> $entries
     */
    public function __construct(
        public DateTimeImmutable $date,
        public bool $inMonth,
        public bool $isToday,
        public array $entries,
    ) {}
}

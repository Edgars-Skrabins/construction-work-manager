<?php

declare(strict_types=1);

namespace App\Web\Shared\Calendar;

use DateTimeImmutable;

final readonly class CalendarDay
{
    public const int VISIBLE_ENTRIES = 3;

    /**
     * @param list<CalendarEntry> $entries
     */
    public function __construct(
        public DateTimeImmutable $date,
        public bool $inMonth,
        public bool $isToday,
        public array $entries,
    ) {}

    public function visibleCount(): int
    {
        $count = count($this->entries);

        return $count <= self::VISIBLE_ENTRIES ? $count : self::VISIBLE_ENTRIES - 1;
    }

    public function hiddenCount(): int
    {
        return count($this->entries) - $this->visibleCount();
    }
}

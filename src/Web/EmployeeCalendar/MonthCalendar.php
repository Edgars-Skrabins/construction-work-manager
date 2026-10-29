<?php

declare(strict_types=1);

namespace App\Web\EmployeeCalendar;

use App\Entity\AssignmentListItem;
use DateTimeImmutable;

final readonly class MonthCalendar
{
    private const string DATE_FORMAT = 'Y-m-d';
    private const string MONTH_FORMAT = 'Y-m';

    private function __construct(
        private DateTimeImmutable $month,
        private DateTimeImmutable $today,
    ) {}

    public static function create(?string $month, DateTimeImmutable $today): self
    {
        $today = new DateTimeImmutable($today->format(self::DATE_FORMAT));

        return new self(self::parse($month) ?? $today->modify('first day of this month'), $today);
    }

    public function month(): DateTimeImmutable
    {
        return $this->month;
    }

    public function from(): DateTimeImmutable
    {
        return $this->month->modify(sprintf('-%d days', (int) $this->month->format('N') - 1));
    }

    public function to(): DateTimeImmutable
    {
        $last = $this->month->modify('last day of this month');

        return $last->modify(sprintf('+%d days', 7 - (int) $last->format('N')));
    }

    public function previousMonth(): string
    {
        return $this->month->modify('-1 month')->format(self::MONTH_FORMAT);
    }

    public function nextMonth(): string
    {
        return $this->month->modify('+1 month')->format(self::MONTH_FORMAT);
    }

    public function currentMonth(): string
    {
        return $this->today->format(self::MONTH_FORMAT);
    }

    /**
     * @param list<AssignmentListItem> $items
     * @return list<list<CalendarDay>>
     */
    public function weeks(array $items): array
    {
        $entries = array_map(
            fn(AssignmentListItem $item): CalendarEntry => new CalendarEntry($item, $item->assignment->displayStatus($this->today)),
            $items,
        );

        $days = [];
        for ($day = $this->from(); $day <= $this->to(); $day = $day->modify('+1 day')) {
            $date = $day->format(self::DATE_FORMAT);
            $days[] = new CalendarDay(
                date: $day,
                inMonth: $day->format(self::MONTH_FORMAT) === $this->month->format(self::MONTH_FORMAT),
                isToday: $date === $this->today->format(self::DATE_FORMAT),
                entries: array_values(array_filter(
                    $entries,
                    static fn(CalendarEntry $entry): bool => $entry->item->assignment->startDate->format(self::DATE_FORMAT) <= $date
                        && $date <= $entry->item->assignment->endDate->format(self::DATE_FORMAT),
                )),
            );
        }

        return array_chunk($days, 7);
    }

    private static function parse(?string $month): ?DateTimeImmutable
    {
        if ($month === null || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $month, $matches) !== 1) {
            return null;
        }
        $year = (int) $matches[1];

        return $year >= 2000 && $year <= 2100 ? new DateTimeImmutable($month . '-01') : null;
    }
}

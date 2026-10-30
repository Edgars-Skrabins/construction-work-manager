<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Shared\Calendar;

use App\Entity\Assignment;
use App\Entity\AssignmentListItem;
use App\Entity\AssignmentStatus;
use App\Tests\FixedClock;
use App\Web\Shared\Calendar\CalendarDay;
use App\Web\Shared\Calendar\CalendarEntry;
use App\Web\Shared\Calendar\MonthCalendar;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

final class MonthCalendarTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function months(): iterable
    {
        yield 'no month' => [null, '2026-10-01'];
        yield 'valid month' => ['2027-02', '2027-02-01'];
        yield 'lowest year' => ['2000-01', '2000-01-01'];
        yield 'highest year' => ['2100-12', '2100-12-01'];
        yield 'empty' => ['', '2026-10-01'];
        yield 'month 13' => ['2026-13', '2026-10-01'];
        yield 'month 00' => ['2026-00', '2026-10-01'];
        yield 'single digit month' => ['2026-2', '2026-10-01'];
        yield 'with a day' => ['2026-02-01', '2026-10-01'];
        yield 'year too low' => ['1999-12', '2026-10-01'];
        yield 'year too high' => ['2101-01', '2026-10-01'];
        yield 'trailing newline' => ["2026-02\n", '2026-10-01'];
        yield 'text' => ['next', '2026-10-01'];
    }

    #[DataProvider('months')]
    public function testInvalidMonthFallsBackToTheCurrentMonthInRiga(?string $month, string $expected): void
    {
        assertSame($expected, $this->calendar($month)->month()->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{array<mixed>, string|null}>
     */
    public static function queries(): iterable
    {
        yield 'nothing' => [[], null];
        yield 'month only' => [['month' => '2027-02'], '2027-02'];
        yield 'picker' => [['year' => '2030', 'monthOfYear' => '03'], '2030-03'];
        yield 'picker wins over month' => [['month' => '2027-02', 'year' => '2030', 'monthOfYear' => '03'], '2030-03'];
        yield 'year without month of year' => [['month' => '2027-02', 'year' => '2030'], null];
        yield 'month of year without year' => [['monthOfYear' => '03'], null];
        yield 'array values' => [['year' => ['2030'], 'monthOfYear' => '03', 'month' => '2027-02'], null];
        yield 'array month' => [['month' => ['2027-02']], null];
    }

    /**
     * @param array<mixed> $query
     */
    #[DataProvider('queries')]
    public function testRequestedMonthPrefersThePickerOverTheMonthParameter(array $query, ?string $expected): void
    {
        assertSame($expected, MonthCalendar::requestedMonth($query));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function pickerValues(): iterable
    {
        yield 'valid' => ['2030', '03', '2030-03-01'];
        yield 'single digit month' => ['2030', '3', '2026-10-01'];
        yield 'month 13' => ['2030', '13', '2026-10-01'];
        yield 'year too low' => ['1999', '12', '2026-10-01'];
        yield 'year too high' => ['2101', '01', '2026-10-01'];
        yield 'year not a number' => ['abc', '03', '2026-10-01'];
        yield 'empty year' => ['', '03', '2026-10-01'];
    }

    #[DataProvider('pickerValues')]
    public function testPickerValuesAreValidatedLikeTheMonthParameter(string $year, string $monthOfYear, string $expected): void
    {
        $month = MonthCalendar::requestedMonth(['year' => $year, 'monthOfYear' => $monthOfYear]);

        assertSame($expected, $this->calendar($month)->month()->format('Y-m-d'));
    }

    public function testTodayIsTakenInRigaNotUtc(): void
    {
        $justAfterRigaMidnight = (new DateTimeImmutable('2026-10-31 22:30:00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Riga'));

        $calendar = MonthCalendar::create(null, (new FixedClock($justAfterRigaMidnight))->now());

        assertSame('2026-11', $calendar->currentMonth());
        assertSame('2026-11-01', $calendar->month()->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{string, string, string, int}>
     */
    public static function ranges(): iterable
    {
        yield 'month starting on Thursday' => ['2026-10', '2026-09-28', '2026-11-01', 5];
        yield 'month starting on Monday' => ['2026-06', '2026-06-01', '2026-07-05', 5];
        yield 'month starting on Sunday' => ['2026-11', '2026-10-26', '2026-12-06', 6];
        yield 'February in four full weeks' => ['2027-02', '2027-02-01', '2027-02-28', 4];
    }

    #[DataProvider('ranges')]
    public function testRangeRunsFromMondayToSunday(string $month, string $from, string $to, int $weeks): void
    {
        $calendar = $this->calendar($month);

        assertSame($from, $calendar->from()->format('Y-m-d'));
        assertSame($to, $calendar->to()->format('Y-m-d'));
        $grid = $calendar->weeks([]);
        assertCount($weeks, $grid);
        foreach ($grid as $week) {
            assertCount(7, $week);
            assertSame('1', $week[0]->date->format('N'));
        }
    }

    public function testNavigationCrossesYearBoundaries(): void
    {
        assertSame(['2025-12', '2026-02'], [$this->calendar('2026-01')->previousMonth(), $this->calendar('2026-01')->nextMonth()]);
        assertSame(['2026-11', '2027-01'], [$this->calendar('2026-12')->previousMonth(), $this->calendar('2026-12')->nextMonth()]);
        assertSame('2026-10', $this->calendar('2027-05')->currentMonth());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function todays(): iterable
    {
        yield 'current month' => ['2026-10', ['2026-10-29']];
        yield 'next month shows it in its first week' => ['2026-11', ['2026-10-29']];
        yield 'month without today in its weeks' => ['2026-12', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('todays')]
    public function testOnlyTodayIsFlagged(string $month, array $expected): void
    {
        $today = array_filter($this->days($this->calendar($month)->weeks([])), static fn(CalendarDay $day): bool => $day->isToday);

        assertSame($expected, array_values(array_map(static fn(CalendarDay $day): string => $day->date->format('Y-m-d'), $today)));
    }

    public function testDaysOutsideTheMonthAreMarked(): void
    {
        $inMonth = array_map(static fn(CalendarDay $day): bool => $day->inMonth, $this->days($this->calendar('2026-10')->weeks([])));

        assertSame([false, false, false, true], array_slice($inMonth, 0, 4));
        assertSame(31, count(array_filter($inMonth)));
        assertSame(false, $inMonth[count($inMonth) - 1]);
    }

    public function testEntriesAreOnEveryVisibleDayOfTheirPeriodWithTheStatusForToday(): void
    {
        $spanning = self::item(1, '2026-09-15', '2026-10-02', AssignmentStatus::Active);
        $planned = self::item(2, '2026-10-30', '2026-11-03', AssignmentStatus::Active);
        $done = self::item(3, '2026-10-29', '2026-10-29', AssignmentStatus::Done);

        $byDate = [];
        foreach ($this->days($this->calendar('2026-10')->weeks([$spanning, $planned, $done])) as $day) {
            $byDate[$day->date->format('Y-m-d')] = array_map(
                static fn(CalendarEntry $entry): string => $entry->item->assignment->id . ' ' . $entry->status->name,
                $day->entries,
            );
        }

        assertSame(['1 Overdue'], $byDate['2026-09-28']);
        assertSame(['1 Overdue'], $byDate['2026-10-02']);
        assertSame([], $byDate['2026-10-03']);
        assertSame(['3 Done'], $byDate['2026-10-29']);
        assertSame(['2 Planned'], $byDate['2026-10-30']);
        assertSame(['2 Planned'], $byDate['2026-11-01']);
    }

    private function calendar(?string $month): MonthCalendar
    {
        return MonthCalendar::create($month, new DateTimeImmutable('2026-10-29 15:00:00', new DateTimeZone('Europe/Riga')));
    }

    /**
     * @param list<list<CalendarDay>> $weeks
     * @return list<CalendarDay>
     */
    private function days(array $weeks): array
    {
        return array_merge(...$weeks);
    }

    private static function item(int $id, string $start, string $end, AssignmentStatus $status): AssignmentListItem
    {
        $now = new DateTimeImmutable('2026-09-01');

        return new AssignmentListItem(
            assignment: new Assignment($id, 4, 1, 1, new DateTimeImmutable($start), new DateTimeImmutable($end), $status, 2, $now, $now),
            employeeName: 'Mārtiņš Liepiņš',
            employeeAccessLevel: 1,
            employeeAccessLevelName: 'General',
            workItemName: 'Concrete works',
            constructionSiteName: 'Āgenskalns Residential Block',
            siteRequiredAccessLevel: 1,
            siteRequiredAccessLevelName: 'General',
        );
    }
}

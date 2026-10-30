<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Shared\Calendar;

use App\Entity\Assignment;
use App\Entity\AssignmentDisplayStatus;
use App\Entity\AssignmentListItem;
use App\Entity\AssignmentStatus;
use App\Web\Shared\Calendar\CalendarDay;
use App\Web\Shared\Calendar\CalendarEntry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class CalendarDayTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function counts(): iterable
    {
        yield 'no entries' => [0, 0, 0];
        yield 'one entry' => [1, 1, 0];
        yield 'three entries fit' => [3, 3, 0];
        yield 'four entries show two and two more' => [4, 2, 2];
        yield 'seven entries show two and five more' => [7, 2, 5];
    }

    #[DataProvider('counts')]
    public function testABusyDayShowsTwoEntriesAndCountsTheRest(int $entries, int $visible, int $hidden): void
    {
        $ids = $entries === 0 ? [] : range(1, $entries);
        $day = new CalendarDay(new DateTimeImmutable('2030-03-12'), true, false, array_map(self::entry(...), $ids));

        assertSame([$visible, $hidden], [$day->visibleCount(), $day->hiddenCount()]);
    }

    private static function entry(int $id): CalendarEntry
    {
        $date = new DateTimeImmutable('2030-03-12');

        return new CalendarEntry(
            new AssignmentListItem(
                assignment: new Assignment($id, 4, 1, 1, $date, $date, AssignmentStatus::Active, 2, $date, $date),
                employeeName: 'Mārtiņš Liepiņš',
                employeeAccessLevel: 1,
                employeeAccessLevelName: 'General',
                workItemName: 'Concrete works',
                constructionSiteName: 'Āgenskalns Residential Block',
                siteRequiredAccessLevel: 1,
                siteRequiredAccessLevelName: 'General',
            ),
            AssignmentDisplayStatus::Planned,
        );
    }
}

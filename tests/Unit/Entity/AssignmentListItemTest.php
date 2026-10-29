<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Assignment;
use App\Entity\AssignmentListItem;
use App\Entity\AssignmentStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class AssignmentListItemTest extends TestCase
{
    #[DataProvider('levels')]
    public function testAccessLevelTooLowOnlyForActiveAssignmentsBelowTheSiteLevel(AssignmentStatus $status, int $employeeLevel, int $siteLevel, bool $expected): void
    {
        $item = new AssignmentListItem(
            assignment: new Assignment(
                id: 1,
                employeeId: 4,
                workItemId: 1,
                constructionSiteId: 1,
                startDate: new DateTimeImmutable('2026-11-10'),
                endDate: new DateTimeImmutable('2026-11-20'),
                status: $status,
                createdById: 2,
                createdAt: new DateTimeImmutable('2026-11-01'),
                updatedAt: new DateTimeImmutable('2026-11-01'),
            ),
            employeeName: 'Mārtiņš Liepiņš',
            employeeAccessLevel: $employeeLevel,
            workItemName: 'Concrete works',
            constructionSiteName: 'Āgenskalns Residential Block',
            siteRequiredAccessLevel: $siteLevel,
        );

        assertSame($expected, $item->accessLevelTooLow());
    }

    /**
     * @return iterable<string, array{AssignmentStatus, int, int, bool}>
     */
    public static function levels(): iterable
    {
        yield 'active, level below the site' => [AssignmentStatus::Active, 2, 3, true];
        yield 'active, level equal to the site' => [AssignmentStatus::Active, 3, 3, false];
        yield 'active, level above the site' => [AssignmentStatus::Active, 4, 3, false];
        yield 'done, level below the site' => [AssignmentStatus::Done, 2, 3, false];
        yield 'cancelled, level below the site' => [AssignmentStatus::Cancelled, 2, 3, false];
    }
}

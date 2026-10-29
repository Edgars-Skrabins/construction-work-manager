<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Assignment;
use App\Entity\AssignmentDisplayStatus;
use App\Entity\AssignmentStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class AssignmentTest extends TestCase
{
    #[DataProvider('displayStatuses')]
    public function testDisplayStatusFollowsStoredStatusAndPeriod(AssignmentStatus $status, string $today, AssignmentDisplayStatus $expected): void
    {
        $assignment = new Assignment(
            id: 1,
            employeeId: 4,
            workItemId: 1,
            constructionSiteId: 1,
            startDate: new DateTimeImmutable('2026-11-10'),
            endDate: new DateTimeImmutable('2026-11-20'),
            status: $status,
            createdById: 2,
            createdAt: new DateTimeImmutable('2026-11-01 08:00:00'),
            updatedAt: new DateTimeImmutable('2026-11-01 08:00:00'),
        );

        assertSame($expected, $assignment->displayStatus(new DateTimeImmutable($today)));
    }

    /**
     * @return iterable<string, array{AssignmentStatus, string, AssignmentDisplayStatus}>
     */
    public static function displayStatuses(): iterable
    {
        yield 'active, the day before the start' => [AssignmentStatus::Active, '2026-11-09', AssignmentDisplayStatus::Planned];
        yield 'active, on the start date' => [AssignmentStatus::Active, '2026-11-10', AssignmentDisplayStatus::InProgress];
        yield 'active, within the period' => [AssignmentStatus::Active, '2026-11-15', AssignmentDisplayStatus::InProgress];
        yield 'active, on the end date' => [AssignmentStatus::Active, '2026-11-20 23:59:59', AssignmentDisplayStatus::InProgress];
        yield 'active, the day after the end' => [AssignmentStatus::Active, '2026-11-21', AssignmentDisplayStatus::Overdue];

        foreach ([AssignmentStatus::Done, AssignmentStatus::Cancelled] as $status) {
            $expected = $status === AssignmentStatus::Done ? AssignmentDisplayStatus::Done : AssignmentDisplayStatus::Cancelled;
            foreach (['before' => '2026-11-09', 'within' => '2026-11-15', 'after' => '2026-11-21'] as $when => $today) {
                yield "{$status->value}, $when the period" => [$status, $today, $expected];
            }
        }
    }
}

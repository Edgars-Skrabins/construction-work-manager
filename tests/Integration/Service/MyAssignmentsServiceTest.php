<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Access\ForbiddenException;
use App\Entity\AssignedSite;
use App\Entity\AssignedWork;
use App\Entity\AssignmentDisplayStatus;
use App\Entity\AssignmentStatus;
use App\Repository\AssignmentRepositoryInterface;
use App\Service\MyAssignmentsService;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;

use function PHPUnit\Framework\assertSame;

final class MyAssignmentsServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->db()->beginTransaction();
        $this->db()->createCommand('DELETE FROM ASSIGNMENT')->execute();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testCurrentWorkIsGroupedBySiteAndFinishedWorkIsHistory(): void
    {
        $welding = $this->insert(6, 3, 3, -1, 1);
        $electrical = $this->insert(6, 2, 1, 5, 10);
        $concrete = $this->insert(6, 1, 1, -10, -5);
        $done = $this->insert(6, 4, 3, -30, -20, AssignmentStatus::Done);
        $cancelled = $this->insert(6, 5, 1, -8, -3, AssignmentStatus::Cancelled);
        $this->insert(7, 5, 2, -1, 1);

        $mine = $this->myAssignments()->forEmployee(6);

        assertSame(
            [
                [[$concrete, AssignmentDisplayStatus::Overdue], [$electrical, AssignmentDisplayStatus::Planned]],
                [[$welding, AssignmentDisplayStatus::InProgress]],
            ],
            array_map(static fn(AssignedSite $site): array => self::idsAndStatuses($site->rows), $mine->sites),
        );
        assertSame('Āgenskalns Residential Block', $mine->sites[0]->first()->item->constructionSiteName);
        assertSame(
            [[$cancelled, AssignmentDisplayStatus::Cancelled], [$done, AssignmentDisplayStatus::Done]],
            self::idsAndStatuses($mine->history),
        );
    }

    public function testEmployeeWithoutAssignmentsGetsEmptyLists(): void
    {
        $this->insert(6, 1, 1, -1, 1);
        $this->insert(6, 2, 1, -10, -5, AssignmentStatus::Done);

        $mine = $this->myAssignments()->forEmployee(5);

        assertSame([[], []], [$mine->sites, $mine->history]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonEmployees(): iterable
    {
        yield 'manager of an assigned employee' => [2];
        yield 'admin' => [1];
    }

    #[DataProvider('nonEmployees')]
    public function testOtherRolesAreForbidden(int $employeeId): void
    {
        $this->expectException(ForbiddenException::class);

        $this->myAssignments()->forEmployee($employeeId);
    }

    /**
     * @param list<array{work: AssignedWork, status: AssignmentDisplayStatus}> $rows
     * @return list<array{int, AssignmentDisplayStatus}>
     */
    private static function idsAndStatuses(array $rows): array
    {
        return array_map(static fn(array $row): array => [$row['work']->item->assignment->id, $row['status']], $rows);
    }

    private function insert(int $employeeId, int $workItemId, int $siteId, int $startOffset, int $endOffset, AssignmentStatus $status = AssignmentStatus::Active): int
    {
        $today = $this->service(ClockInterface::class)->now()->setTime(0, 0);
        $start = $today->modify(sprintf('%+d days', $startOffset));
        $end = $today->modify(sprintf('%+d days', $endOffset));
        $repository = $this->service(AssignmentRepositoryInterface::class);

        $id = $repository->create($employeeId, $workItemId, $siteId, $start, $end, 1);
        if ($status !== AssignmentStatus::Active) {
            $repository->updatePeriodAndStatus($id, $start, $end, $status);
        }

        return $id;
    }

    private function myAssignments(): MyAssignmentsService
    {
        return $this->service(MyAssignmentsService::class);
    }
}

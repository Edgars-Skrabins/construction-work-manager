<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Access\Scope;
use App\Entity\AssignmentListItem;
use App\Entity\AssignmentStatus;
use App\Entity\EmployeeNeedingAccess;
use App\Repository\AssignmentRepositoryInterface;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class AssignmentRepositoryTest extends IntegrationTestCase
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

    public function testCreateStoresAnActiveAssignment(): void
    {
        $id = $this->insert(5, 2, 2, '2026-11-10', '2026-11-20', createdBy: 2);

        $assignment = $this->repository()->find($id, 1, Scope::All);

        assertNotNull($assignment);
        assertSame([5, 2, 2, 2], [$assignment->employeeId, $assignment->workItemId, $assignment->constructionSiteId, $assignment->createdById]);
        assertSame(['2026-11-10', '2026-11-20'], [$assignment->startDate->format('Y-m-d'), $assignment->endDate->format('Y-m-d')]);
        assertSame(AssignmentStatus::Active, $assignment->status);
    }

    public function testUpdatePeriodAndStatusChangesOnlyThoseFields(): void
    {
        $id = $this->insert(5, 2, 2, '2026-11-10', '2026-11-20');

        $this->repository()->updatePeriodAndStatus($id, new DateTimeImmutable('2026-11-12'), new DateTimeImmutable('2026-11-25'), AssignmentStatus::Done);
        $assignment = $this->repository()->find($id, 1, Scope::All);

        assertNotNull($assignment);
        assertSame(['2026-11-12', '2026-11-25'], [$assignment->startDate->format('Y-m-d'), $assignment->endDate->format('Y-m-d')]);
        assertSame(AssignmentStatus::Done, $assignment->status);
        assertSame([5, 2, 2], [$assignment->employeeId, $assignment->workItemId, $assignment->constructionSiteId]);
    }

    public function testDeleteRemovesTheAssignment(): void
    {
        $id = $this->insert(5, 2, 2, '2026-11-10', '2026-11-20');

        $this->repository()->delete($id);

        assertNull($this->repository()->find($id, 1, Scope::All));
    }

    /**
     * @param list<int> $visible
     */
    #[DataProvider('scopes')]
    public function testScopeLimitsFindAndList(int $viewerId, Scope $scope, array $visible): void
    {
        $ids = [];
        foreach ([4, 5, 6, 7, 8] as $employeeId) {
            $ids[$employeeId] = $this->insert($employeeId, 1, 1, '2026-11-10', '2026-11-20');
        }

        foreach ($ids as $employeeId => $id) {
            $found = $this->repository()->find($id, $viewerId, $scope);
            assertSame(in_array($employeeId, $visible, true), $found !== null, "Employee $employeeId's assignment");
        }

        $listed = array_map(
            static fn(AssignmentListItem $item): int => $item->assignment->employeeId,
            $this->list($viewerId, $scope, 'employee'),
        );
        sort($listed);
        assertSame($visible, $listed);
    }

    /**
     * @return iterable<string, array{int, Scope, list<int>}>
     */
    public static function scopes(): iterable
    {
        yield 'admin sees all' => [1, Scope::All, [4, 5, 6, 7, 8]];
        yield 'manager 2 sees employees 4 to 6' => [2, Scope::Subordinates, [4, 5, 6]];
        yield 'manager 3 sees employees 7 and 8' => [3, Scope::Subordinates, [7, 8]];
        yield 'employee 4 sees their own' => [4, Scope::Own, [4]];
    }

    public function testListItemCarriesNamesAndLevels(): void
    {
        $this->insert(5, 3, 3, '2026-11-10', '2026-11-20');

        $items = $this->list(1, Scope::All, '');

        assertCount(1, $items);
        assertSame(
            ['Laura Krūmiņa', 2, 'Welding', 'Rīga Thermal Power Plant Upgrade', 3, true],
            [$items[0]->employeeName, $items[0]->employeeAccessLevel, $items[0]->workItemName, $items[0]->constructionSiteName, $items[0]->siteRequiredAccessLevel, $items[0]->accessLevelTooLow()],
        );
    }

    public function testDefaultOrderIsStartDateNewestFirstThenIdNewestFirst(): void
    {
        $older = $this->insert(4, 1, 1, '2026-11-01', '2026-11-20');
        $first = $this->insert(5, 1, 1, '2026-11-10', '2026-11-20');
        $second = $this->insert(6, 1, 1, '2026-11-10', '2026-11-20');

        assertSame([$second, $first, $older], $this->listedIds(''));
        assertSame([$second, $first, $older], $this->listedIds('unknownColumn'));
    }

    /**
     * @param list<string> $expectedOrder
     */
    #[DataProvider('sorts')]
    public function testWhitelistedSortKeys(string $sort, array $expectedOrder): void
    {
        $this->insert(4, 1, 1, '2026-11-01', '2026-11-30');
        $this->insert(5, 3, 3, '2026-11-05', '2026-11-10');
        $this->insert(6, 2, 2, '2026-11-03', '2026-11-20');

        $names = array_map(
            static fn(AssignmentListItem $item): string => $item->employeeName,
            $this->list(1, Scope::All, $sort),
        );

        assertSame($expectedOrder, $names);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function sorts(): iterable
    {
        yield 'employee' => ['employee', ['Laura Krūmiņa', 'Mārtiņš Liepiņš', 'Kristaps Vītols']];
        yield 'employee descending' => ['-employee', ['Kristaps Vītols', 'Mārtiņš Liepiņš', 'Laura Krūmiņa']];
        yield 'work item' => ['workItem', ['Mārtiņš Liepiņš', 'Kristaps Vītols', 'Laura Krūmiņa']];
        yield 'site' => ['site', ['Mārtiņš Liepiņš', 'Kristaps Vītols', 'Laura Krūmiņa']];
        yield 'start date' => ['startDate', ['Mārtiņš Liepiņš', 'Kristaps Vītols', 'Laura Krūmiņa']];
        yield 'end date' => ['endDate', ['Laura Krūmiņa', 'Kristaps Vītols', 'Mārtiņš Liepiņš']];
    }

    public function testSortTiesAreBrokenById(): void
    {
        $first = $this->insert(4, 1, 1, '2026-11-01', '2026-11-30');
        $second = $this->insert(4, 1, 1, '2026-12-01', '2026-12-30');

        assertSame([$first, $second], $this->listedIds('employee'));
        assertSame([$second, $first], $this->listedIds('-employee'));
    }

    public function testListIsPagedByTwenty(): void
    {
        for ($day = 1; $day <= 25; $day++) {
            $this->insert(4, 1, 1, sprintf('2026-11-%02d', $day), '2026-11-30');
        }

        $paginator = $this->repository()->listPaginator(2, Scope::Subordinates, '');

        assertSame(20, $paginator->getPageSize());
        assertSame(25, $paginator->getTotalItems());
        assertSame(2, $paginator->getTotalPages());
        assertCount(5, iterator_to_array($paginator->withCurrentPage(2)->read(), false));
    }

    /**
     * @param array{string, string, string}|null $other
     */
    #[DataProvider('overlaps')]
    public function testFindOverlappingActive(?array $other, bool $overlaps): void
    {
        if ($other !== null) {
            $id = $this->insert(4, 1, 1, $other[0], $other[1]);
            $this->repository()->updatePeriodAndStatus($id, new DateTimeImmutable($other[0]), new DateTimeImmutable($other[1]), AssignmentStatus::from($other[2]));
        }

        $found = $this->repository()->findOverlappingActive(4, 1, 1, new DateTimeImmutable('2026-11-10'), new DateTimeImmutable('2026-11-20'), null);

        assertSame($overlaps, $found !== null);
    }

    /**
     * @return iterable<string, array{array{string, string, string}|null, bool}>
     */
    public static function overlaps(): iterable
    {
        yield 'none' => [null, false];
        yield 'inside' => [['2026-11-12', '2026-11-15', 'active'], true];
        yield 'ends on the start date' => [['2026-11-01', '2026-11-10', 'active'], true];
        yield 'starts on the end date' => [['2026-11-20', '2026-11-30', 'active'], true];
        yield 'ends the day before' => [['2026-11-01', '2026-11-09', 'active'], false];
        yield 'starts the day after' => [['2026-11-21', '2026-11-30', 'active'], false];
        yield 'done' => [['2026-11-12', '2026-11-15', 'done'], false];
        yield 'cancelled' => [['2026-11-12', '2026-11-15', 'cancelled'], false];
    }

    public function testOverlapIgnoresTheExceptedAssignmentAndOtherWork(): void
    {
        $id = $this->insert(4, 1, 1, '2026-11-10', '2026-11-20');
        $this->insert(4, 2, 1, '2026-11-10', '2026-11-20');
        $this->insert(5, 1, 1, '2026-11-10', '2026-11-20');
        $this->insert(4, 1, 2, '2026-11-10', '2026-11-20');

        $start = new DateTimeImmutable('2026-11-10');
        $end = new DateTimeImmutable('2026-11-20');

        assertSame($id, $this->repository()->findOverlappingActive(4, 1, 1, $start, $end, null)?->id);
        assertNull($this->repository()->findOverlappingActive(4, 1, 1, $start, $end, $id));
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('accessDates')]
    public function testNeedsAccessIncludesBothEndDates(string $date, array $expected): void
    {
        $this->insert(5, 1, 2, '2026-11-10', '2026-11-20');

        assertSame($expected, $this->employeeIdsNeedingAccess(2, $date));
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function accessDates(): iterable
    {
        yield 'the day before the start' => ['2026-11-09', []];
        yield 'the start date' => ['2026-11-10', [5]];
        yield 'within' => ['2026-11-15', [5]];
        yield 'the end date' => ['2026-11-20', [5]];
        yield 'the day after the end' => ['2026-11-21', []];
    }

    public function testNeedsAccessExcludesDoneAndCancelledAndOtherSites(): void
    {
        $done = $this->insert(5, 1, 2, '2026-11-10', '2026-11-20');
        $this->repository()->updatePeriodAndStatus($done, new DateTimeImmutable('2026-11-10'), new DateTimeImmutable('2026-11-20'), AssignmentStatus::Done);
        $cancelled = $this->insert(8, 1, 2, '2026-11-10', '2026-11-20');
        $this->repository()->updatePeriodAndStatus($cancelled, new DateTimeImmutable('2026-11-10'), new DateTimeImmutable('2026-11-20'), AssignmentStatus::Cancelled);
        $this->insert(6, 1, 3, '2026-11-10', '2026-11-20');

        assertSame([], $this->employeeIdsNeedingAccess(2, '2026-11-15'));
        assertSame([6], $this->employeeIdsNeedingAccess(3, '2026-11-15'));
    }

    public function testNeedsAccessRechecksAccessLevelsLive(): void
    {
        $this->insert(6, 1, 3, '2026-11-10', '2026-11-20');
        $this->insert(7, 1, 3, '2026-11-10', '2026-11-20');

        $this->db()->createCommand('UPDATE EMPLOYEE SET ACCESS_LEVEL_ID = 2 WHERE ID = 6')->execute();
        assertSame([7], $this->employeeIdsNeedingAccess(3, '2026-11-15'));

        $this->db()->createCommand('UPDATE CONSTRUCTION_SITE SET REQUIRED_ACCESS_LEVEL_ID = 4 WHERE ID = 3')->execute();
        assertSame([7], $this->employeeIdsNeedingAccess(3, '2026-11-15'));

        $this->db()->createCommand('UPDATE EMPLOYEE SET ACCESS_LEVEL_ID = 3 WHERE ID = 7')->execute();
        assertSame([], $this->employeeIdsNeedingAccess(3, '2026-11-15'));
    }

    public function testNeedsAccessListsEachEmployeeOnceInNameOrderWithTheirFields(): void
    {
        $this->insert(6, 1, 1, '2026-11-10', '2026-11-20');
        $this->insert(4, 1, 1, '2026-11-10', '2026-11-20');
        $this->insert(4, 2, 1, '2026-11-01', '2026-11-30');
        $this->insert(5, 1, 1, '2026-11-15', '2026-11-15');

        $employees = $this->repository()->findEmployeesNeedingAccess(1, new DateTimeImmutable('2026-11-15'));

        assertEquals(
            [
                new EmployeeNeedingAccess(5, 'Laura', 'Krūmiņa', 2, 'Restricted'),
                new EmployeeNeedingAccess(4, 'Mārtiņš', 'Liepiņš', 1, 'General'),
                new EmployeeNeedingAccess(6, 'Kristaps', 'Vītols', 3, 'Critical infrastructure'),
            ],
            $employees,
        );
    }

    private function repository(): AssignmentRepositoryInterface
    {
        return $this->service(AssignmentRepositoryInterface::class);
    }

    private function insert(int $employeeId, int $workItemId, int $siteId, string $start, string $end, int $createdBy = 1): int
    {
        return $this->repository()->create($employeeId, $workItemId, $siteId, new DateTimeImmutable($start), new DateTimeImmutable($end), $createdBy);
    }

    /**
     * @return list<AssignmentListItem>
     */
    private function list(int $viewerId, Scope $scope, string $sort): array
    {
        return iterator_to_array($this->repository()->listPaginator($viewerId, $scope, $sort)->read(), false);
    }

    /**
     * @return list<int>
     */
    private function listedIds(string $sort): array
    {
        return array_map(
            static fn(AssignmentListItem $item): int => $item->assignment->id,
            $this->list(1, Scope::All, $sort),
        );
    }

    /**
     * @phpstan-impure
     * @return list<int>
     */
    private function employeeIdsNeedingAccess(int $siteId, string $date): array
    {
        return array_map(
            static fn(EmployeeNeedingAccess $employee): int => $employee->employeeId,
            $this->repository()->findEmployeesNeedingAccess($siteId, new DateTimeImmutable($date)),
        );
    }
}

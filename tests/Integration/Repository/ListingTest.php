<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Access\Scope;
use App\Entity\ConstructionSite;
use App\Entity\EmployeeListItem;
use App\Entity\WorkItem;
use App\Repository\ConstructionSiteRepositoryInterface;
use App\Repository\EmployeeRepositoryInterface;
use App\Repository\WorkItemRepositoryInterface;
use App\Tests\Integration\IntegrationTestCase;
use App\Web\Shared\Listing\ListParams;
use PHPUnit\Framework\Attributes\DataProvider;
use Yiisoft\Data\Paginator\OffsetPaginator;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

final class ListingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sortKeys(): iterable
    {
        foreach (['name', 'birthDate', 'accessLevel', 'role', 'manager'] as $key) {
            yield "employees by $key" => ['employees', $key];
        }
        foreach (['name', 'city', 'country', 'area', 'accessLevel'] as $key) {
            yield "sites by $key" => ['sites', $key];
        }
        yield 'work items by name' => ['workItems', 'name'];
    }

    #[DataProvider('sortKeys')]
    public function testDescendingIsTheExactReverseOfAscendingIncludingTies(string $list, string $key): void
    {
        $ascending = $this->ids($this->paginator($list, $key)->withPageSize(100));
        $descending = $this->ids($this->paginator($list, '-' . $key)->withPageSize(100));

        assertNotSame([], $ascending);
        assertSame(array_reverse($ascending), $descending);
    }

    /**
     * @return iterable<string, array{string, string, list<int>}>
     */
    public static function knownOrders(): iterable
    {
        yield 'employees by name' => ['employees', 'name', [9, 23, 45, 54, 27, 75, 37, 1, 48, 31, 61, 69, 32, 34, 14, 46, 35, 22, 67, 70]];
        yield 'employees by birth date' => ['employees', 'birthDate', [80, 33, 76, 41, 56, 65, 71, 24, 69, 34, 62, 27, 59, 15, 66, 72, 67, 32, 11, 9]];
        yield 'employees by access level, ties by ID' => ['employees', 'accessLevel', [4, 17, 21, 25, 30, 33, 36, 38, 41, 45, 50, 53, 56, 58, 61, 65, 70, 73, 76, 78]];
        yield 'employees by role, ties by ID' => ['employees', 'role', [1, 9, 10, 4, 5, 6, 7, 8, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27]];
        yield 'sites by area' => ['sites', 'area', [6, 2, 1, 10, 7, 4, 8, 9, 3, 5, 11]];
        yield 'sites by access level' => ['sites', '-accessLevel', [10, 4, 8, 5, 3, 11, 7, 2, 9, 6, 1]];
        yield 'work items by name' => ['workItems', 'name', [9, 1, 21, 10, 6, 2, 25, 18, 16, 13, 15, 24, 22, 8, 12, 7, 11, 14, 23, 5]];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('knownOrders')]
    public function testSortsByTheColumnWithIdAsTieBreak(string $list, string $sort, array $expected): void
    {
        assertSame($expected, $this->ids($this->paginator($list, $sort)));
    }

    public function testUnknownSortKeyFallsBackToName(): void
    {
        foreach (['employees', 'sites', 'workItems'] as $list) {
            assertSame($this->ids($this->paginator($list, 'name')), $this->ids($this->paginator($list, 'PASSWORD_HASH')));
            assertSame($this->ids($this->paginator($list, 'name')), $this->ids($this->paginator($list, '')));
        }
    }

    /**
     * @return iterable<string, array{int, Scope, list<int>}>
     */
    public static function employeeScopes(): iterable
    {
        yield 'admin sees everyone' => [1, Scope::All, [1, 8, 2, 5, 4, 3, 6, 7]];
        yield 'manager sees their subordinates' => [2, Scope::Subordinates, [5, 4, 6]];
        yield 'other manager sees their subordinates' => [3, Scope::Subordinates, [8, 7]];
        yield 'own scope sees only the viewer' => [4, Scope::Own, [4]];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('employeeScopes')]
    public function testEmployeeListIsScoped(int $viewerId, Scope $scope, array $expected): void
    {
        $paginator = $this->service(EmployeeRepositoryInterface::class)->listPaginator($viewerId, $scope, 'name', '');

        assertSame($expected, $this->ids($paginator));
    }

    public function testSearchStaysInsideTheScope(): void
    {
        $repository = $this->service(EmployeeRepositoryInterface::class);

        assertSame([5], $this->ids($repository->listPaginator(2, Scope::Subordinates, 'name', 'Laura')));
        assertSame([], $this->ids($repository->listPaginator(2, Scope::Subordinates, 'name', 'Anna')));
        assertSame([7], $this->ids($repository->listPaginator(1, Scope::All, 'name', 'Anna')));
    }

    public function testPagesHoldTwentyRowsAndAPagePastTheEndShowsTheLastOne(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->db()->createCommand()->insert('WORK_ITEM', ['NAME' => sprintf('Listing test %02d', $i)])->execute();
        }

        $paginator = $this->service(WorkItemRepositoryInterface::class)->listPaginator('name', '');
        assertCount(20, iterator_to_array($paginator->read(), false));
        assertSame(3, $paginator->getTotalPages());

        $last = (new ListParams('name', 9))->applyTo($paginator);
        assertSame(3, $last->getCurrentPage());
        assertCount(10, iterator_to_array($last->read(), false));
    }

    /**
     * @return iterable<string, array{string, string, list<int>}>
     */
    public static function searches(): iterable
    {
        yield 'employees by first name' => ['employees', 'Laura', [5]];
        yield 'employees by last name' => ['employees', 'Kalniņa', [2, 76]];
        yield 'employees by part of a name' => ['employees', 'iņš', [1, 3, 4, 16, 18, 32, 40, 54, 75, 77]];
        yield 'employees by first and last name' => ['employees', 'Jānis Bērziņš', [1]];
        yield 'employees by last and first name' => ['employees', 'Bērziņš, Jānis', [1]];
        yield 'employees ignoring case' => ['employees', 'ANNA zariņa', [7]];
        yield 'employees by email' => ['employees', 'manager2@', [3]];
        yield 'employees by part of an email' => ['employees', 'employee3', [6]];
        yield 'employees without a match' => ['employees', 'Nobody', []];
        yield 'sites by name' => ['sites', 'school', [2]];
        yield 'sites by street' => ['sites', 'Viskaļu', [3]];
        yield 'sites by city' => ['sites', 'Rīga', [1, 3]];
        yield 'sites by country' => ['sites', 'latvia', [1, 2, 3, 4, 5, 6, 7, 8]];
        yield 'sites without a match' => ['sites', 'Tartu', []];
        yield 'work items by name' => ['workItems', 'roof', [5, 13, 19, 20]];
        yield 'work items by description' => ['workItems', 'pipework', [3, 14, 16]];
        yield 'work items without a match' => ['workItems', 'Asbestos', []];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('searches')]
    public function testSearchFindsRowsContainingTheText(string $list, string $search, array $expected): void
    {
        $ids = $this->ids($this->paginator($list, 'name', $search));
        sort($ids);

        assertSame($expected, $ids);
        assertSame(count($expected), $this->paginator($list, 'name', $search)->getTotalItems());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function wildcards(): iterable
    {
        yield 'percent' => ['100%', ['100% done']];
        yield 'underscore' => ['a_b', ['a_b']];
        yield 'brackets' => ['[x]', ['[x] item']];
        yield 'closing bracket' => ['x]', ['[x] item']];
        yield 'caret in brackets' => ['[^a]', []];
        yield 'backslash' => ['a\\b', ['a\\b']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('wildcards')]
    public function testWildcardsInTheSearchAreMatchedLiterally(string $search, array $expected): void
    {
        foreach (['100% done', '100x done', 'a_b', 'axb', '[x] item', 'x item', 'a\\b'] as $name) {
            $this->db()->createCommand()->insert('WORK_ITEM', ['NAME' => $name])->execute();
        }

        $names = array_map(
            static fn(WorkItem $workItem): string => $workItem->name,
            iterator_to_array($this->service(WorkItemRepositoryInterface::class)->listPaginator('name', $search)->read(), false),
        );

        assertSame($expected, $names);
    }

    public function testSortingKeepsTheSearch(): void
    {
        $ascending = $this->ids($this->paginator('employees', 'name', 'iņš'));
        $descending = $this->ids($this->paginator('employees', '-name', 'iņš'));

        assertSame([54, 75, 1, 32, 16, 4, 3, 18, 77, 40], $ascending);
        assertSame(array_reverse($ascending), $descending);
    }

    public function testTotalAndPagesFollowTheSearch(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->db()->createCommand()->insert('WORK_ITEM', ['NAME' => sprintf('Search test %02d', $i)])->execute();
        }

        $paginator = $this->service(WorkItemRepositoryInterface::class)->listPaginator('name', 'search test');
        assertSame(25, $paginator->getTotalItems());
        assertSame(2, $paginator->getTotalPages());

        $last = (new ListParams('name', 2, 'search test'))->applyTo($paginator);
        assertCount(5, iterator_to_array($last->read(), false));
    }

    /**
     * @return OffsetPaginator<int, EmployeeListItem>|OffsetPaginator<int, ConstructionSite>|OffsetPaginator<int, WorkItem>
     */
    private function paginator(string $list, string $sort, string $search = ''): OffsetPaginator
    {
        return match ($list) {
            'employees' => $this->service(EmployeeRepositoryInterface::class)->listPaginator(1, Scope::All, $sort, $search),
            'sites' => $this->service(ConstructionSiteRepositoryInterface::class)->listPaginator($sort, $search),
            default => $this->service(WorkItemRepositoryInterface::class)->listPaginator($sort, $search),
        };
    }

    /**
     * @param OffsetPaginator<int, EmployeeListItem>|OffsetPaginator<int, ConstructionSite>|OffsetPaginator<int, WorkItem> $paginator
     * @return list<int>
     */
    private function ids(OffsetPaginator $paginator): array
    {
        return array_map(static fn(EmployeeListItem|ConstructionSite|WorkItem $row): int => $row->id, iterator_to_array($paginator->read(), false));
    }
}

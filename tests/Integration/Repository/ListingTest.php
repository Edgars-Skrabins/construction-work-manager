<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

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
        $ascending = $this->ids($this->paginator($list, $key));
        $descending = $this->ids($this->paginator($list, '-' . $key));

        assertNotSame([], $ascending);
        assertSame(array_reverse($ascending), $descending);
    }

    /**
     * @return iterable<string, array{string, string, list<int>}>
     */
    public static function knownOrders(): iterable
    {
        yield 'employees by name' => ['employees', 'name', [1, 8, 2, 5, 4, 3, 6, 7]];
        yield 'employees by birth date' => ['employees', 'birthDate', [3, 1, 2, 8, 6, 4, 5, 7]];
        yield 'employees by access level, ties by ID' => ['employees', 'accessLevel', [4, 5, 8, 3, 6, 1, 2, 7]];
        yield 'employees by role, ties by ID' => ['employees', 'role', [1, 4, 5, 6, 7, 8, 2, 3]];
        yield 'sites by area' => ['sites', 'area', [2, 1, 4, 3]];
        yield 'sites by access level' => ['sites', '-accessLevel', [4, 3, 2, 1]];
        yield 'work items by name' => ['workItems', 'name', [1, 2, 5, 4, 3]];
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

    public function testPagesHoldTwentyRowsAndAPagePastTheEndShowsTheLastOne(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->db()->createCommand()->insert('WORK_ITEM', ['NAME' => sprintf('Listing test %02d', $i)])->execute();
        }

        $paginator = $this->service(WorkItemRepositoryInterface::class)->listPaginator('name', '');
        assertCount(20, iterator_to_array($paginator->read(), false));
        assertSame(2, $paginator->getTotalPages());

        $last = (new ListParams('name', 9))->applyTo($paginator);
        assertSame(2, $last->getCurrentPage());
        assertCount(10, iterator_to_array($last->read(), false));
    }

    /**
     * @return iterable<string, array{string, string, list<int>}>
     */
    public static function searches(): iterable
    {
        yield 'employees by first name' => ['employees', 'Laura', [5]];
        yield 'employees by last name' => ['employees', 'Kalniņa', [2]];
        yield 'employees by part of a name' => ['employees', 'iņš', [1, 3, 4]];
        yield 'employees by first and last name' => ['employees', 'Jānis Bērziņš', [1]];
        yield 'employees by last and first name' => ['employees', 'Bērziņš, Jānis', [1]];
        yield 'employees ignoring case' => ['employees', 'ANNA zariņa', [7]];
        yield 'employees by email' => ['employees', 'manager2@', [3]];
        yield 'employees by part of an email' => ['employees', 'employee5', [8]];
        yield 'employees without a match' => ['employees', 'Nobody', []];
        yield 'sites by name' => ['sites', 'school', [2]];
        yield 'sites by street' => ['sites', 'Viskaļu', [3]];
        yield 'sites by city' => ['sites', 'Rīga', [1, 3]];
        yield 'sites by country' => ['sites', 'latvia', [1, 2, 3, 4]];
        yield 'sites without a match' => ['sites', 'Liepāja', []];
        yield 'work items by name' => ['workItems', 'roof', [5]];
        yield 'work items by description' => ['workItems', 'pipework', [3]];
        yield 'work items without a match' => ['workItems', 'Plumbing', []];
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

        assertSame([1, 4, 3], $ascending);
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
            'employees' => $this->service(EmployeeRepositoryInterface::class)->listPaginator($sort, $search),
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

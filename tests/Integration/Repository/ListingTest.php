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

        $paginator = $this->service(WorkItemRepositoryInterface::class)->listPaginator('name');
        assertCount(20, iterator_to_array($paginator->read(), false));
        assertSame(2, $paginator->getTotalPages());

        $last = (new ListParams('name', 9))->applyTo($paginator);
        assertSame(2, $last->getCurrentPage());
        assertCount(10, iterator_to_array($last->read(), false));
    }

    /**
     * @return OffsetPaginator<int, EmployeeListItem>|OffsetPaginator<int, ConstructionSite>|OffsetPaginator<int, WorkItem>
     */
    private function paginator(string $list, string $sort): OffsetPaginator
    {
        return match ($list) {
            'employees' => $this->service(EmployeeRepositoryInterface::class)->listPaginator($sort),
            'sites' => $this->service(ConstructionSiteRepositoryInterface::class)->listPaginator($sort),
            default => $this->service(WorkItemRepositoryInterface::class)->listPaginator($sort),
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

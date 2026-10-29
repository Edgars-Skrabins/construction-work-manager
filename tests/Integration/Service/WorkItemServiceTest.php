<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Access\ForbiddenException;
use App\Repository\WorkItemData;
use App\Service\InUseException;
use App\Service\ValidationException;
use App\Service\WorkItemService;
use App\Tests\Integration\IntegrationTestCase;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class WorkItemServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testCreateAndUpdate(): void
    {
        $id = $this->workItems()->create(1, new WorkItemData('Plastering', null));
        $this->workItems()->update(1, $id, new WorkItemData('Plastering works', 'Walls and ceilings.'));

        $workItem = $this->workItems()->find(1, $id);
        assertNotNull($workItem);
        assertSame('Plastering works', $workItem->name);
        assertSame('Walls and ceilings.', $workItem->description);
    }

    public function testDuplicateNameIgnoringCaseIsRejected(): void
    {
        try {
            $this->workItems()->create(1, new WorkItemData('WELDING', null));
            self::fail('Expected a duplicate name to be rejected.');
        } catch (ValidationException $e) {
            assertSame(['name' => 'A work item with this name already exists.'], $e->errors);
        }
    }

    public function testKeepingTheOwnNameOnUpdateIsAllowed(): void
    {
        $this->workItems()->update(1, 3, new WorkItemData('Welding', 'Steel only.'));

        assertSame('Steel only.', $this->workItems()->find(1, 3)?->description);
    }

    public function testUnusedWorkItemIsDeleted(): void
    {
        $this->workItems()->delete(1, 5);

        assertNull($this->workItems()->find(1, 5));
    }

    public function testWorkItemInUseIsNotDeleted(): void
    {
        $this->db()->createCommand('CREATE TABLE TEST_WORK_ITEM_REFERENCE (WORK_ITEM_ID INT NOT NULL REFERENCES WORK_ITEM (ID))')->execute();
        $this->db()->createCommand('INSERT INTO TEST_WORK_ITEM_REFERENCE (WORK_ITEM_ID) VALUES (5)')->execute();

        try {
            $this->workItems()->delete(1, 5);
            self::fail('Expected the delete to be refused.');
        } catch (InUseException $e) {
            assertSame("This work item is still in use and can't be deleted.", $e->getMessage());
        }
        assertNotNull($this->workItems()->find(1, 5));
    }

    /**
     * @param Closure(WorkItemService): void $call
     */
    #[DataProvider('calls')]
    public function testManagerIsForbidden(Closure $call): void
    {
        $this->expectException(ForbiddenException::class);

        $call($this->workItems());
    }

    /**
     * @return iterable<string, array{Closure(WorkItemService): void}>
     */
    public static function calls(): iterable
    {
        yield 'list' => [static function (WorkItemService $s): void {
            $s->listPaginator(2, '');
        }];
        yield 'find' => [static function (WorkItemService $s): void {
            $s->find(2, 1);
        }];
        yield 'create' => [static function (WorkItemService $s): void {
            $s->create(2, new WorkItemData('Painting', null));
        }];
        yield 'update' => [static function (WorkItemService $s): void {
            $s->update(2, 1, new WorkItemData('Painting', null));
        }];
        yield 'delete' => [static function (WorkItemService $s): void {
            $s->delete(2, 5);
        }];
    }

    private function workItems(): WorkItemService
    {
        return $this->service(WorkItemService::class);
    }
}

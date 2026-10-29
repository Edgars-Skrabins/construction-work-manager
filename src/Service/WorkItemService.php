<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\AccessChecker;
use App\Access\Permission;
use App\Entity\WorkItem;
use App\Repository\WorkItemData;
use App\Repository\WorkItemRepositoryInterface;
use Yiisoft\Data\Paginator\OffsetPaginator;

final readonly class WorkItemService
{
    public function __construct(
        private WorkItemRepositoryInterface $workItems,
        private AccessChecker $accessChecker,
    ) {}

    /**
     * @return OffsetPaginator<int, WorkItem>
     */
    public function listPaginator(int $actorId, string $sort): OffsetPaginator
    {
        $this->accessChecker->ensure($actorId, Permission::WorkItemUpdate);

        return $this->workItems->listPaginator($sort);
    }

    public function find(int $actorId, int $id): ?WorkItem
    {
        $this->accessChecker->ensure($actorId, Permission::WorkItemUpdate);

        return $this->workItems->find($id);
    }

    public function create(int $actorId, WorkItemData $data): int
    {
        $this->accessChecker->ensure($actorId, Permission::WorkItemCreate);
        $this->ensureUniqueName($data->name, null);

        return $this->workItems->create($data);
    }

    public function update(int $actorId, int $id, WorkItemData $data): void
    {
        $this->accessChecker->ensure($actorId, Permission::WorkItemUpdate);
        $this->ensureUniqueName($data->name, $id);

        $this->workItems->update($id, $data);
    }

    public function delete(int $actorId, int $id): void
    {
        $this->accessChecker->ensure($actorId, Permission::WorkItemDelete);

        InUseException::guard(fn() => $this->workItems->delete($id), "This work item is still in use and can't be deleted.");
    }

    private function ensureUniqueName(string $name, ?int $exceptId): void
    {
        if ($this->workItems->nameExists($name, $exceptId)) {
            throw ValidationException::forField('name', 'A work item with this name already exists.');
        }
    }
}

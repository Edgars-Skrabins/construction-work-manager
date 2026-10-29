<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\AccessChecker;
use App\Access\Permission;
use App\Entity\WorkItem;
use App\Repository\WorkItemData;
use App\Repository\WorkItemRepositoryInterface;
use Closure;
use Yiisoft\Data\Paginator\OffsetPaginator;

final readonly class WorkItemService
{
    private const string NAME_TAKEN = 'A work item with this name already exists.';

    public function __construct(
        private WorkItemRepositoryInterface $workItems,
        private AccessChecker $accessChecker,
    ) {}

    /**
     * @return OffsetPaginator<int, WorkItem>
     */
    public function listPaginator(int $actorId, string $sort, string $search): OffsetPaginator
    {
        $this->accessChecker->ensure($actorId, Permission::WorkItemUpdate);

        return $this->workItems->listPaginator($sort, $search);
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

        return $this->guardName(fn(): int => $this->workItems->create($data));
    }

    public function update(int $actorId, int $id, WorkItemData $data): void
    {
        $this->accessChecker->ensure($actorId, Permission::WorkItemUpdate);
        $this->ensureUniqueName($data->name, $id);

        $this->guardName(fn() => $this->workItems->update($id, $data));
    }

    public function delete(int $actorId, int $id): void
    {
        $this->accessChecker->ensure($actorId, Permission::WorkItemDelete);

        InUseException::guard(fn() => $this->workItems->delete($id), "This work item is still in use and can't be deleted.");
    }

    private function ensureUniqueName(string $name, ?int $exceptId): void
    {
        if ($this->workItems->nameExists($name, $exceptId)) {
            throw ValidationException::forField('name', self::NAME_TAKEN);
        }
    }

    /**
     * @template T
     * @param Closure(): T $write
     * @return T
     */
    private function guardName(Closure $write): mixed
    {
        return ValidationException::guardUnique($write, 'UQ_WORK_ITEM_NAME', 'name', self::NAME_TAKEN);
    }
}

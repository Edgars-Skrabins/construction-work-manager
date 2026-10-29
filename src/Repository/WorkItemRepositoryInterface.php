<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WorkItem;
use Yiisoft\Data\Paginator\OffsetPaginator;

interface WorkItemRepositoryInterface
{
    /**
     * @return OffsetPaginator<int, WorkItem>
     */
    public function listPaginator(string $sort, string $search): OffsetPaginator;

    public function find(int $id): ?WorkItem;

    /**
     * @return list<WorkItem>
     */
    public function all(): array;

    public function nameExists(string $name, ?int $exceptId): bool;

    public function create(WorkItemData $data): int;

    public function update(int $id, WorkItemData $data): void;

    public function delete(int $id): void;
}

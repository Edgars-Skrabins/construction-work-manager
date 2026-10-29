<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConstructionSite;
use Yiisoft\Data\Paginator\OffsetPaginator;

interface ConstructionSiteRepositoryInterface
{
    /**
     * @return OffsetPaginator<int, ConstructionSite>
     */
    public function listPaginator(string $sort, string $search): OffsetPaginator;

    public function find(int $id): ?ConstructionSite;

    public function findForUpdate(int $id): ?ConstructionSite;

    /**
     * @return list<ConstructionSite>
     */
    public function all(): array;

    public function findByCode(string $code): ?ConstructionSite;

    public function nameExists(string $name, ?int $exceptId): bool;

    public function codeExists(string $code, ?int $exceptId): bool;

    public function create(ConstructionSiteData $data): int;

    public function update(int $id, ConstructionSiteData $data): void;

    public function delete(int $id): void;
}

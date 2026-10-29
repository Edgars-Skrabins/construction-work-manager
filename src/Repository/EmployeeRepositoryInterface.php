<?php

declare(strict_types=1);

namespace App\Repository;

use App\Access\Role;
use App\Entity\Employee;
use App\Entity\EmployeeListItem;
use Yiisoft\Data\Paginator\OffsetPaginator;

interface EmployeeRepositoryInterface
{
    /**
     * @return OffsetPaginator<int, EmployeeListItem>
     */
    public function listPaginator(string $sort, string $search): OffsetPaginator;

    public function find(int $id): ?Employee;

    public function roleOf(int $id): ?Role;

    /**
     * @return list<Employee>
     */
    public function withRole(Role $role, ?int $managerId = null, ?int $exceptId = null): array;

    public function hasSubordinates(int $id): bool;

    public function create(EmployeeData $data): int;

    public function update(int $id, EmployeeData $data, bool $roleChanged): void;

    public function delete(int $id): void;
}

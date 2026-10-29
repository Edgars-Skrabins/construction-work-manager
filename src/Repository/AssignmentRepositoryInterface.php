<?php

declare(strict_types=1);

namespace App\Repository;

use App\Access\Scope;
use App\Entity\AssignedWork;
use App\Entity\Assignment;
use App\Entity\AssignmentListItem;
use App\Entity\AssignmentStatus;
use App\Entity\EmployeeNeedingAccess;
use DateTimeImmutable;
use Yiisoft\Data\Paginator\OffsetPaginator;

interface AssignmentRepositoryInterface
{
    public function find(int $id, int $viewerId, Scope $scope): ?Assignment;

    public function findListItem(int $id, int $viewerId, Scope $scope): ?AssignmentListItem;

    /**
     * @return OffsetPaginator<int, AssignmentListItem>
     */
    public function listPaginator(int $viewerId, Scope $scope, string $sort): OffsetPaginator;

    /**
     * @return list<AssignmentListItem>
     */
    public function listInPeriod(int $viewerId, Scope $scope, DateTimeImmutable $from, DateTimeImmutable $to, ?int $employeeId): array;

    /**
     * @return list<AssignedWork>
     */
    public function findAssignedWork(int $employeeId): array;

    public function hasActiveForEmployee(int $employeeId): bool;

    public function findOverlappingActive(
        int $employeeId,
        int $workItemId,
        int $constructionSiteId,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate,
        ?int $exceptId,
    ): ?Assignment;

    public function create(
        int $employeeId,
        int $workItemId,
        int $constructionSiteId,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate,
        int $createdById,
    ): int;

    public function updatePeriodAndStatus(int $id, DateTimeImmutable $startDate, DateTimeImmutable $endDate, AssignmentStatus $status): void;

    public function delete(int $id): void;

    /**
     * @return list<EmployeeNeedingAccess>
     */
    public function findEmployeesNeedingAccess(int $constructionSiteId, DateTimeImmutable $date): array;
}

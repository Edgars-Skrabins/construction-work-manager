<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\AccessChecker;
use App\Access\Permission;
use App\Access\Scope;
use App\Entity\AssignedSite;
use App\Entity\AssignedWork;
use App\Entity\Assignment;
use App\Entity\AssignmentDisplayStatus;
use App\Entity\AssignmentStatus;
use App\Repository\AssignmentRepositoryInterface;
use App\Repository\EmployeeRepositoryInterface;
use DateTimeImmutable;

use function sprintf;

final readonly class MyAssignmentsService
{
    public function __construct(
        private AssignmentRepositoryInterface $assignments,
        private EmployeeRepositoryInterface $employees,
        private AssignmentService $assignmentService,
        private AccessChecker $accessChecker,
    ) {}

    public function forEmployee(int $employeeId): MyAssignments
    {
        $this->accessChecker->ensure($employeeId, Permission::AssignmentReadOwn);

        $sites = [];
        $history = [];
        foreach ($this->assignments->findAssignedWork($employeeId) as $work) {
            $assignment = $work->item->assignment;
            $row = ['work' => $work, 'status' => $this->assignmentService->displayStatus($assignment)];
            if ($assignment->status === AssignmentStatus::Active) {
                $sites[$assignment->constructionSiteId][] = $row;
            } else {
                $history[] = $row;
            }
        }

        usort($history, static fn(array $a, array $b): int => [$b['work']->item->assignment->endDate, $b['work']->item->assignment->id]
            <=> [$a['work']->item->assignment->endDate, $a['work']->item->assignment->id]);

        return new MyAssignments(
            sites: array_map(static fn(array $rows): AssignedSite => new AssignedSite($rows), array_values($sites)),
            history: $history,
            managerName: $this->employees->managerNameOf($employeeId),
        );
    }

    public function find(int $employeeId, int $id): AssignedWork
    {
        $this->accessChecker->ensure($employeeId, Permission::AssignmentReadOwn);

        return $this->assignments->findAssignedWorkItem($employeeId, $id)
            ?? throw new NotFoundException(sprintf('Assignment %d is not assigned to employee %d.', $id, $employeeId));
    }

    public function displayStatus(Assignment $assignment): AssignmentDisplayStatus
    {
        return $this->assignmentService->displayStatus($assignment);
    }

    /**
     * @return list<AssignedWork>
     */
    public function calendar(int $employeeId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->accessChecker->ensure($employeeId, Permission::AssignmentReadOwn);

        return $this->assignments->findAssignedWorkInPeriod($employeeId, $from, $to);
    }

    /**
     * @return array{int, int}|null
     */
    public function calendarYears(int $employeeId): ?array
    {
        $this->accessChecker->ensure($employeeId, Permission::AssignmentReadOwn);

        return $this->assignments->yearRange($employeeId, Scope::Own, null);
    }
}

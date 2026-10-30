<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\AccessChecker;
use App\Access\Permission;
use App\Access\Scope;
use App\Entity\AssignedSite;
use App\Entity\AssignmentListItem;
use App\Entity\AssignmentStatus;
use App\Repository\AssignmentRepositoryInterface;
use App\Repository\EmployeeRepositoryInterface;
use DateTimeImmutable;

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

    /**
     * @return list<AssignmentListItem>
     */
    public function calendar(int $employeeId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->accessChecker->ensure($employeeId, Permission::AssignmentReadOwn);

        return $this->assignments->listInPeriod($employeeId, Scope::Own, $from, $to, null);
    }
}

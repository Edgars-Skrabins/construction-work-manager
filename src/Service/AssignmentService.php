<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\AccessChecker;
use App\Access\AccessScope;
use App\Access\Entity;
use App\Access\ForbiddenException;
use App\Access\Permission;
use App\Access\Role;
use App\Access\Scope;
use App\Entity\Assignment;
use App\Entity\AssignmentDisplayStatus;
use App\Entity\AssignmentListItem;
use App\Entity\AssignmentStatus;
use App\Entity\ConstructionSite;
use App\Entity\Employee;
use App\Repository\AssignmentRepositoryInterface;
use App\Repository\ConstructionSiteRepositoryInterface;
use App\Repository\EmployeeRepositoryInterface;
use App\Repository\WorkItemRepositoryInterface;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Yiisoft\Data\Paginator\OffsetPaginator;

use function sprintf;

final readonly class AssignmentService
{
    private const string DATE_FORMAT = 'Y-m-d';
    private const array INVALID_PERIOD = ['startDate' => 'Enter a valid start date.'];

    public function __construct(
        private AssignmentRepositoryInterface $assignments,
        private EmployeeRepositoryInterface $employees,
        private ConstructionSiteRepositoryInterface $sites,
        private WorkItemRepositoryInterface $workItems,
        private AccessChecker $accessChecker,
        private AccessScope $accessScope,
        private ClockInterface $clock,
    ) {}

    /**
     * @return OffsetPaginator<int, AssignmentListItem>
     */
    public function list(int $actorId, string $sort): OffsetPaginator
    {
        $this->accessChecker->ensure($actorId, Permission::AssignmentRead);

        return $this->assignments->listPaginator($actorId, $this->scope($actorId), $sort);
    }

    public function find(int $actorId, int $id): ?AssignmentListItem
    {
        $this->accessChecker->ensure($actorId, Permission::AssignmentRead);

        return $this->assignments->findListItem($id, $actorId, $this->scope($actorId));
    }

    public function formOptions(int $actorId): AssignmentFormOptions
    {
        $this->accessChecker->ensure($actorId, Permission::AssignmentCreate);

        return new AssignmentFormOptions(
            employees: $this->employees->withRole(Role::Employee, $this->scope($actorId) === Scope::All ? null : $actorId),
            sites: $this->sites->all(),
            workItems: $this->workItems->all(),
        );
    }

    public function create(
        int $actorId,
        int $employeeId,
        int $workItemId,
        int $constructionSiteId,
        string $startDate,
        string $endDate,
    ): int {
        $this->accessChecker->ensure($actorId, Permission::AssignmentCreate);

        $employee = $this->employees->find($employeeId);
        if ($employee === null || !$this->isAssignable($employee, $actorId)) {
            throw new ForbiddenException(sprintf('Employee %d may not assign employee %d.', $actorId, $employeeId));
        }

        $errors = [];
        $period = $this->period($startDate, $endDate, $errors);
        if ($this->workItems->find($workItemId) === null) {
            $errors['workItem'] = 'Choose a work item.';
        }
        $site = $this->sites->find($constructionSiteId);
        if ($site === null) {
            $errors['constructionSite'] = 'Choose a construction site.';
        } else {
            $this->checkAccessLevel($employee, $site, $errors);
        }
        if ($period !== null && $errors === []) {
            $this->checkOverlap($employeeId, $workItemId, $constructionSiteId, $period, null, $errors);
        }

        if ($errors !== [] || $period === null) {
            throw new ValidationException($errors !== [] ? $errors : self::INVALID_PERIOD);
        }

        return $this->assignments->create($employeeId, $workItemId, $constructionSiteId, $period[0], $period[1], $actorId);
    }

    public function update(int $actorId, int $id, string $startDate, string $endDate, string $status): void
    {
        $this->accessChecker->ensure($actorId, Permission::AssignmentUpdate);
        $assignment = $this->scopedOrFail($actorId, $id);

        $errors = [];
        $period = $this->period($startDate, $endDate, $errors);
        $newStatus = AssignmentStatus::tryFrom($status);
        if ($newStatus === null) {
            $errors['status'] = 'Choose a status.';
        }

        if ($newStatus === AssignmentStatus::Active) {
            $employee = $this->employees->find($assignment->employeeId);
            $site = $this->sites->find($assignment->constructionSiteId);
            if ($employee !== null && $site !== null) {
                $this->checkAccessLevel($employee, $site, $errors);
            }
            if ($period !== null && $errors === []) {
                $this->checkOverlap($assignment->employeeId, $assignment->workItemId, $assignment->constructionSiteId, $period, $assignment->id, $errors);
            }
        }

        if ($errors !== [] || $period === null || $newStatus === null) {
            throw new ValidationException($errors !== [] ? $errors : self::INVALID_PERIOD);
        }

        $this->assignments->updatePeriodAndStatus($assignment->id, $period[0], $period[1], $newStatus);
    }

    public function delete(int $actorId, int $id): void
    {
        $this->accessChecker->ensure($actorId, Permission::AssignmentDelete);

        $this->assignments->delete($this->scopedOrFail($actorId, $id)->id);
    }

    public function displayStatus(Assignment $assignment): AssignmentDisplayStatus
    {
        return $assignment->displayStatus($this->clock->now()->setTime(0, 0));
    }

    private function scope(int $actorId): Scope
    {
        $actor = $this->employees->find($actorId);
        if ($actor === null) {
            throw new ForbiddenException(sprintf('Employee %d does not exist.', $actorId));
        }

        return $this->accessScope->forRole($actor->role, Entity::Assignment);
    }

    private function scopedOrFail(int $actorId, int $id): Assignment
    {
        return $this->assignments->find($id, $actorId, $this->scope($actorId))
            ?? throw new NotFoundException(sprintf('Assignment %d is not reachable for employee %d.', $id, $actorId));
    }

    private function isAssignable(Employee $employee, int $actorId): bool
    {
        if ($employee->role !== Role::Employee) {
            return false;
        }

        return match ($this->scope($actorId)) {
            Scope::All => true,
            Scope::Subordinates => $employee->managerId === $actorId,
            Scope::Own => $employee->id === $actorId,
        };
    }

    /**
     * @param array<string, string> $errors
     * @return array{DateTimeImmutable, DateTimeImmutable}|null
     */
    private function period(string $startDate, string $endDate, array &$errors): ?array
    {
        $start = self::date($startDate);
        $end = self::date($endDate);

        if ($start === null) {
            $errors['startDate'] = self::INVALID_PERIOD['startDate'];
        }
        if ($end === null) {
            $errors['endDate'] = 'Enter a valid end date.';
        } elseif ($start !== null && $end < $start) {
            $errors['endDate'] = "The end date can't be before the start date.";
        }

        return $start !== null && $end !== null && $end >= $start ? [$start, $end] : null;
    }

    /**
     * @param array<string, string> $errors
     */
    private function checkAccessLevel(Employee $employee, ConstructionSite $site, array &$errors): void
    {
        if ($employee->accessLevelId < $site->requiredAccessLevelId) {
            $errors['constructionSite'] = sprintf(
                'The site requires access level %d; the employee has level %d.',
                $site->requiredAccessLevelId,
                $employee->accessLevelId,
            );
        }
    }

    /**
     * @param array{DateTimeImmutable, DateTimeImmutable} $period
     * @param array<string, string> $errors
     */
    private function checkOverlap(
        int $employeeId,
        int $workItemId,
        int $constructionSiteId,
        array $period,
        ?int $exceptId,
        array &$errors,
    ): void {
        $other = $this->assignments->findOverlappingActive($employeeId, $workItemId, $constructionSiteId, $period[0], $period[1], $exceptId);
        if ($other !== null) {
            $errors['startDate'] = sprintf(
                'The employee already has this work item at this site from %s to %s.',
                $other->startDate->format(self::DATE_FORMAT),
                $other->endDate->format(self::DATE_FORMAT),
            );
        }
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, trim($value));

        return $date !== false && $date->format(self::DATE_FORMAT) === trim($value) ? $date : null;
    }
}

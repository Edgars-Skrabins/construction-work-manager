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
use App\Entity\AccessLevel;
use App\Entity\Assignment;
use App\Entity\AssignmentDescription;
use App\Entity\AssignmentDisplayStatus;
use App\Entity\AssignmentListItem;
use App\Entity\AssignmentStatus;
use App\Entity\ConstructionSite;
use App\Entity\Employee;
use App\Entity\IsoDate;
use App\Repository\AccessLevelRepositoryInterface;
use App\Repository\AssignmentRepositoryInterface;
use App\Repository\ConstructionSiteRepositoryInterface;
use App\Repository\EmployeeRepositoryInterface;
use App\Repository\TransactionRunnerInterface;
use App\Repository\WorkItemRepositoryInterface;
use Closure;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Yiisoft\Data\Paginator\OffsetPaginator;
use Yiisoft\Db\Exception\SerializationFailureException;
use Yiisoft\Translator\TranslatorInterface;

use function sprintf;

final readonly class AssignmentService
{
    public function __construct(
        private AssignmentRepositoryInterface $assignments,
        private EmployeeRepositoryInterface $employees,
        private ConstructionSiteRepositoryInterface $sites,
        private WorkItemRepositoryInterface $workItems,
        private AccessLevelRepositoryInterface $accessLevels,
        private AccessChecker $accessChecker,
        private AccessScope $accessScope,
        private ClockInterface $clock,
        private TransactionRunnerInterface $transaction,
        private TranslatorInterface $translator,
    ) {}

    /**
     * @param array<string, int> $filters
     * @return OffsetPaginator<int, AssignmentListItem>
     */
    public function list(int $actorId, string $sort, string $search, array $filters = []): OffsetPaginator
    {
        $this->accessChecker->ensure($actorId, Permission::AssignmentRead);

        return $this->assignments->listPaginator($actorId, $this->scope($actorId), $sort, $search, $filters);
    }

    public function find(int $actorId, int $id): ?AssignmentListItem
    {
        $this->accessChecker->ensure($actorId, Permission::AssignmentRead);

        return $this->assignments->findListItem($id, $actorId, $this->scope($actorId));
    }

    /**
     * @return list<AssignmentListItem>
     */
    public function calendar(int $actorId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $employeeId): array
    {
        $this->accessChecker->ensure($actorId, Permission::EmployeeList);

        return $this->assignments->listInPeriod($actorId, $this->scope($actorId), $from, $to, $employeeId);
    }

    /**
     * @return array{int, int}|null
     */
    public function calendarYears(int $actorId, ?int $employeeId): ?array
    {
        $this->accessChecker->ensure($actorId, Permission::EmployeeList);

        return $this->assignments->yearRange($actorId, $this->scope($actorId), $employeeId);
    }

    /**
     * @return list<Employee>
     */
    public function teamEmployees(int $actorId): array
    {
        $this->accessChecker->ensure($actorId, Permission::EmployeeList);

        return $this->assignableEmployees($actorId, $this->scope($actorId));
    }

    public function editOptions(int $actorId): AssignmentFormOptions
    {
        $this->accessChecker->ensure($actorId, Permission::AssignmentUpdate);

        return new AssignmentFormOptions(
            employees: $this->assignableEmployees($actorId, $this->scope($actorId)),
            sites: [],
            workItems: [],
            accessLevels: $this->accessLevelsById(),
        );
    }

    public function formOptions(int $actorId): AssignmentFormOptions
    {
        $this->accessChecker->ensure($actorId, Permission::AssignmentCreate);

        return new AssignmentFormOptions(
            employees: $this->assignableEmployees($actorId, $this->scope($actorId)),
            sites: $this->sites->all(),
            workItems: $this->workItems->all(),
            accessLevels: $this->accessLevelsById(),
        );
    }

    public function create(
        int $actorId,
        int $employeeId,
        int $workItemId,
        int $constructionSiteId,
        string $startDate,
        string $endDate,
        string $description = '',
    ): int {
        $this->accessChecker->ensure($actorId, Permission::AssignmentCreate);
        $scope = $this->scope($actorId);

        return $this->save(function () use ($actorId, $scope, $employeeId, $workItemId, $constructionSiteId, $startDate, $endDate, $description): int {
            $employee = $this->employees->findForUpdate($employeeId);
            if ($employee === null || !$this->isAssignable($employee, $actorId, $scope)) {
                throw new ForbiddenException(sprintf('Employee %d may not assign employee %d.', $actorId, $employeeId));
            }

            $errors = [];
            $period = $this->period($startDate, $endDate, $errors);
            $text = $this->description($description, $errors);
            if ($this->workItems->find($workItemId) === null) {
                $errors['workItem'] = $this->translator->translate('Choose a work item.');
            }
            $site = $this->sites->findForUpdate($constructionSiteId);
            if ($site === null) {
                $errors['constructionSite'] = $this->translator->translate('Choose a construction site.');
            } else {
                $this->checkAccessLevel($employee, $site, $errors);
            }
            if ($period !== null && $errors === []) {
                $this->checkOverlap($employeeId, $workItemId, $constructionSiteId, $period, null, $errors);
            }

            if ($errors !== [] || $period === null) {
                throw new ValidationException($errors !== [] ? $errors : $this->invalidPeriod());
            }

            return $this->assignments->create($employeeId, $workItemId, $constructionSiteId, $period[0], $period[1], $actorId, $text);
        });
    }

    public function update(
        int $actorId,
        int $id,
        int $employeeId,
        string $startDate,
        string $endDate,
        string $status,
        string $description,
        ?int $expectedEmployeeId = null,
    ): void {
        $this->accessChecker->ensure($actorId, Permission::AssignmentUpdate);
        $scope = $this->scope($actorId);
        $assignment = $this->scopedOrFail($actorId, $id, $scope);
        $currentEmployeeId = $expectedEmployeeId ?? $assignment->employeeId;

        $this->save(function () use ($actorId, $scope, $assignment, $currentEmployeeId, $employeeId, $startDate, $endDate, $status, $description): void {
            $errors = [];
            $period = $this->period($startDate, $endDate, $errors);
            $text = $this->description($description, $errors);
            $newStatus = AssignmentStatus::tryFrom($status);
            if ($newStatus === null) {
                $errors['status'] = $this->translator->translate('Choose a status.');
            }

            $reassigned = $employeeId !== $currentEmployeeId;
            $employee = $reassigned || $newStatus === AssignmentStatus::Active
                ? $this->lockEmployees($currentEmployeeId, $employeeId)
                : null;
            if ($this->assignments->employeeIdForUpdate($assignment->id) !== $currentEmployeeId) {
                throw ValidationException::forField('employee', $this->concurrentChange());
            }
            if ($reassigned && ($employee === null || !$this->isAssignable($employee, $actorId, $scope))) {
                throw new ForbiddenException(sprintf('Employee %d may not assign employee %d.', $actorId, $employeeId));
            }
            if (!$reassigned && $newStatus === AssignmentStatus::Active && ($employee === null || !$this->isAssignable($employee, $actorId, $scope))) {
                $errors['employee'] = $this->translator->translate('Only employees can have active assignments.');
            }

            if ($newStatus === AssignmentStatus::Active) {
                $site = $this->sites->findForUpdate($assignment->constructionSiteId);
                if ($employee !== null && $site !== null) {
                    $this->checkAccessLevel($employee, $site, $errors);
                }
                if ($period !== null && $errors === []) {
                    $this->checkOverlap($employeeId, $assignment->workItemId, $assignment->constructionSiteId, $period, $assignment->id, $errors);
                }
            }

            if ($errors !== [] || $period === null || $newStatus === null) {
                throw new ValidationException($errors !== [] ? $errors : $this->invalidPeriod());
            }

            $this->assignments->update($assignment->id, $employeeId, $period[0], $period[1], $newStatus, $text);
        });
    }

    public function delete(int $actorId, int $id): void
    {
        $this->accessChecker->ensure($actorId, Permission::AssignmentDelete);

        $this->assignments->delete($this->scopedOrFail($actorId, $id, $this->scope($actorId))->id);
    }

    public function displayStatus(Assignment $assignment): AssignmentDisplayStatus
    {
        return $assignment->displayStatus($this->clock->now()->setTime(0, 0));
    }

    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    private function save(Closure $work): mixed
    {
        try {
            return $this->transaction->run($work);
        } catch (SerializationFailureException) {
            throw ValidationException::forField('startDate', $this->concurrentChange());
        }
    }

    private function scope(int $actorId): Scope
    {
        $role = $this->employees->roleOf($actorId)
            ?? throw new ForbiddenException(sprintf('Employee %d does not exist.', $actorId));

        return $this->accessScope->forRole($role, Entity::Assignment);
    }

    /**
     * @return list<Employee>
     */
    private function assignableEmployees(int $actorId, Scope $scope): array
    {
        return $this->employees->withRole(Role::Employee, $scope === Scope::All ? null : $actorId);
    }

    private function scopedOrFail(int $actorId, int $id, Scope $scope): Assignment
    {
        return $this->assignments->find($id, $actorId, $scope)
            ?? throw new NotFoundException(sprintf('Assignment %d is not reachable for employee %d.', $id, $actorId));
    }

    private function lockEmployees(int $currentId, int $newId): ?Employee
    {
        $ids = array_unique([$currentId, $newId]);
        sort($ids);
        $locked = [];
        foreach ($ids as $id) {
            $locked[$id] = $this->employees->findForUpdate($id);
        }

        return $locked[$newId];
    }

    private function isAssignable(Employee $employee, int $actorId, Scope $scope): bool
    {
        if ($employee->role !== Role::Employee) {
            return false;
        }

        return match ($scope) {
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
        $start = IsoDate::parse(trim($startDate));
        $end = IsoDate::parse(trim($endDate));

        if ($start === null) {
            $errors['startDate'] = $this->invalidPeriod()['startDate'];
        }
        if ($end === null) {
            $errors['endDate'] = $this->translator->translate('Enter a valid end date.');
        } elseif ($start !== null && $end < $start) {
            $errors['endDate'] = $this->translator->translate("The end date can't be before the start date.");
        }

        return $start !== null && $end !== null && $end >= $start ? [$start, $end] : null;
    }

    /**
     * @param array<string, string> $errors
     */
    private function description(string $description, array &$errors): ?string
    {
        $text = AssignmentDescription::normalize($description);
        if (AssignmentDescription::isTooLong($text)) {
            $errors['description'] = $this->translator->translate(
                '{Property} must contain at most {max, number} {max, plural, one{character} other{characters}}.',
                ['Property' => $this->translator->translate('Description'), 'max' => AssignmentDescription::MAX_LENGTH],
            );
        }

        return $text === '' ? null : $text;
    }

    /**
     * @param array<string, string> $errors
     */
    private function checkAccessLevel(Employee $employee, ConstructionSite $site, array &$errors): void
    {
        if ($employee->accessLevelId < $site->requiredAccessLevelId) {
            $errors['constructionSite'] = $this->translator->translate(
                'The site requires {required}; the employee has {actual}.',
                ['required' => $site->requiredAccessLevel()->label(), 'actual' => $this->accessLevelsById()[$employee->accessLevelId]->label()],
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
            $errors['startDate'] = $this->translator->translate(
                'The employee already has this work item at this site from {start} to {end}.',
                ['start' => $other->startDate->format(IsoDate::FORMAT), 'end' => $other->endDate->format(IsoDate::FORMAT)],
            );
        }
    }

    private function concurrentChange(): string
    {
        return $this->translator->translate("The assignment couldn't be saved because of a change made at the same time. Try again.");
    }

    /**
     * @return non-empty-array<string, string>
     */
    private function invalidPeriod(): array
    {
        return ['startDate' => $this->translator->translate('Enter a valid start date.')];
    }

    /**
     * @return array<int, AccessLevel>
     */
    private function accessLevelsById(): array
    {
        $levels = [];
        foreach ($this->accessLevels->all() as $level) {
            $levels[$level->id] = $level;
        }

        return $levels;
    }
}

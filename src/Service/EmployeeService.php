<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\AccessChecker;
use App\Access\AccessScope;
use App\Access\Entity;
use App\Access\ForbiddenException;
use App\Access\Permission;
use App\Access\Role;
use App\Entity\AccessLevel;
use App\Entity\Employee;
use App\Entity\EmployeeListItem;
use App\Repository\AccessLevelRepositoryInterface;
use App\Repository\AccountRepositoryInterface;
use App\Repository\AssignmentRepositoryInterface;
use App\Repository\EmployeeData;
use App\Repository\EmployeeRepositoryInterface;
use App\Repository\TransactionRunnerInterface;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Yiisoft\Data\Paginator\OffsetPaginator;
use Yiisoft\Security\PasswordHasher;

final readonly class EmployeeService
{
    private const string SETUP_CODE_LIFETIME = '+72 hours';
    private const string EMAIL_TAKEN = 'This email is already used by another login.';

    public function __construct(
        private EmployeeRepositoryInterface $employees,
        private AccountRepositoryInterface $accounts,
        private AccessLevelRepositoryInterface $accessLevels,
        private AssignmentRepositoryInterface $assignments,
        private TransactionRunnerInterface $transaction,
        private AccessChecker $accessChecker,
        private AccessScope $accessScope,
        private PasswordHasher $passwordHasher,
        private SetupCodeGenerator $setupCodes,
        private ClockInterface $clock,
    ) {}

    /**
     * @return OffsetPaginator<int, EmployeeListItem>
     */
    public function listPaginator(int $actorId, string $sort, string $search): OffsetPaginator
    {
        $this->accessChecker->ensure($actorId, Permission::EmployeeList);
        $role = $this->employees->roleOf($actorId)
            ?? throw new ForbiddenException(sprintf('Employee %d does not exist.', $actorId));

        return $this->employees->listPaginator($actorId, $this->accessScope->forRole($role, Entity::Employee), $sort, $search);
    }

    public function find(int $actorId, int $id): ?Employee
    {
        $this->accessChecker->ensure($actorId, Permission::EmployeeUpdate);

        return $this->employees->find($id);
    }

    /**
     * @return list<Employee>
     */
    public function managers(int $actorId, ?int $exceptId): array
    {
        $this->accessChecker->ensure($actorId, Permission::EmployeeUpdate);

        return $this->employees->withRole(Role::Manager, exceptId: $exceptId);
    }

    /**
     * @return list<AccessLevel>
     */
    public function accessLevels(int $actorId): array
    {
        $this->accessChecker->ensure($actorId, Permission::EmployeeRead);

        return $this->accessLevels->all();
    }

    public function create(int $actorId, EmployeeInput $input): SavedEmployee
    {
        $this->accessChecker->ensure($actorId, Permission::EmployeeCreate);
        $data = $this->validate($input, null);

        return $this->guardEmail(fn(): SavedEmployee => $this->transaction->run(function () use ($data, $input): SavedEmployee {
            $id = $this->employees->create($data);
            $setupCode = $input->email === null ? null : $this->createAccount($id, $input->email, $input->loginActive);

            return new SavedEmployee($id, $setupCode);
        }));
    }

    public function update(int $actorId, int $id, EmployeeInput $input): SavedEmployee
    {
        $this->accessChecker->ensure($actorId, Permission::EmployeeUpdate);
        $employee = $this->employees->find($id);
        if ($employee === null) {
            throw new InvalidArgumentException(sprintf('Employee %d does not exist.', $id));
        }
        $data = $this->validate($input, $employee, $actorId);

        return $this->guardEmail(fn(): SavedEmployee => $this->transaction->run(function () use ($employee, $data, $input): SavedEmployee {
            $this->employees->update($employee->id, $data, $employee->role !== $data->role);

            return new SavedEmployee($employee->id, $this->updateAccount($employee, $input));
        }));
    }

    public function newSetupCode(int $actorId, int $id): string
    {
        $this->accessChecker->ensure($actorId, Permission::EmployeeUpdate);
        $account = $this->accounts->findByEmployeeId($id);
        if ($account === null || $account->passwordHash !== null) {
            throw ValidationException::forField('setupCode', 'This employee has no pending login.');
        }

        [$code, $hash, $expiresAt] = $this->issueSetupCode();
        $this->accounts->replaceSetupCode($id, $hash, $expiresAt);

        return $code;
    }

    public function delete(int $actorId, int $id): void
    {
        $this->accessChecker->ensure($actorId, Permission::EmployeeDelete);
        if ($id === $actorId) {
            throw new InUseException("You can't delete yourself.");
        }
        if ($this->employees->hasSubordinates($id)) {
            throw new InUseException("Reassign this manager's employees first.");
        }

        $this->transaction->run(function () use ($id): void {
            $this->accounts->delete($id);
            InUseException::guard(fn() => $this->employees->delete($id), "This employee still has assignments and can't be deleted.");
        });
    }

    private function validate(EmployeeInput $input, ?Employee $employee, ?int $actorId = null): EmployeeData
    {
        $errors = [];
        $managerId = $input->role === Role::Employee ? $input->managerId : null;

        $birthDate = $input->birthDate->format('Y-m-d');
        $today = new DateTimeImmutable($this->clock->now()->format('Y-m-d'));
        if ($birthDate > $today->modify('-16 years')->format('Y-m-d')) {
            $errors['birthDate'] = 'The employee must be at least 16 years old.';
        } elseif ($birthDate <= $today->modify('-101 years')->format('Y-m-d')) {
            $errors['birthDate'] = 'The employee must be at most 100 years old.';
        }
        if (!$this->accessLevels->exists($input->accessLevelId)) {
            $errors['accessLevelId'] = 'Choose an access level.';
        }
        if ($managerId !== null && ($managerId === $employee?->id || $this->employees->find($managerId)?->role !== Role::Manager)) {
            $errors['managerId'] = 'Choose a manager.';
        }
        if ($input->email !== null) {
            $owner = $this->accounts->findByEmail($input->email);
            if ($owner !== null && $owner->employeeId !== $employee?->id) {
                $errors['email'] = self::EMAIL_TAKEN;
            }
        }

        if ($employee !== null && $employee->id === $actorId) {
            if ($input->role !== $employee->role) {
                $errors['role'] = "You can't change your own role.";
            }
            if ($employee->account !== null && $input->email === null) {
                $errors['email'] = "You can't remove your own login.";
            } elseif (!$input->loginActive) {
                $errors['loginActive'] = "You can't deactivate your own login.";
            }
        }
        if (
            !isset($errors['role'])
            && $employee?->role === Role::Manager
            && $input->role !== Role::Manager
            && $this->employees->hasSubordinates($employee->id)
        ) {
            $errors['role'] = "Reassign this manager's employees first.";
        }
        if (
            !isset($errors['role'])
            && $employee?->role === Role::Employee
            && $input->role !== Role::Employee
            && $this->assignments->hasActiveForEmployee($employee->id)
        ) {
            $errors['role'] = "Finish or cancel this employee's active assignments first.";
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new EmployeeData(
            firstName: $input->firstName,
            lastName: $input->lastName,
            birthDate: $input->birthDate,
            accessLevelId: $input->accessLevelId,
            role: $input->role,
            managerId: $managerId,
        );
    }

    /**
     * @param Closure(): SavedEmployee $save
     */
    private function guardEmail(Closure $save): SavedEmployee
    {
        return ValidationException::guardUnique($save, 'UQ_ACCOUNT_EMAIL', 'email', self::EMAIL_TAKEN);
    }

    private function updateAccount(Employee $employee, EmployeeInput $input): ?string
    {
        $account = $employee->account;

        if ($account === null) {
            return $input->email === null ? null : $this->createAccount($employee->id, $input->email, $input->loginActive);
        }
        if ($input->email === null) {
            $this->accounts->delete($employee->id);

            return null;
        }
        if (mb_strtolower(trim($input->email)) !== $account->email) {
            $this->accounts->updateEmail($employee->id, $input->email);
        }
        if ($input->loginActive !== $account->isActive) {
            $this->accounts->setActive($employee->id, $input->loginActive);
        }

        return null;
    }

    private function createAccount(int $employeeId, string $email, bool $isActive): string
    {
        [$code, $hash, $expiresAt] = $this->issueSetupCode();
        $this->accounts->create($employeeId, $email, $isActive, $hash, $expiresAt);

        return $code;
    }

    /**
     * @return array{string, string, DateTimeImmutable}
     */
    private function issueSetupCode(): array
    {
        $code = $this->setupCodes->generate();
        $expiresAt = $this->clock->now()->modify(self::SETUP_CODE_LIFETIME);

        return [$this->setupCodes->format($code), $this->passwordHasher->hash($code), $expiresAt];
    }
}

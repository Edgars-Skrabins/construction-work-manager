<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Access\AccessChecker;
use App\Access\AccessScope;
use App\Access\ForbiddenException;
use App\Access\Role;
use App\Entity\Employee;
use App\Entity\EmployeeListItem;
use App\Repository\AccessLevelRepositoryInterface;
use App\Repository\AccountRepositoryInterface;
use App\Repository\AssignmentRepositoryInterface;
use App\Repository\EmployeeRepositoryInterface;
use App\Repository\TransactionRunnerInterface;
use App\Service\EmployeeInput;
use App\Service\EmployeeService;
use App\Service\InUseException;
use App\Service\SetupCodeGenerator;
use App\Service\ValidationException;
use App\Tests\FixedClock;
use App\Tests\Integration\IntegrationTestCase;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Yiisoft\Security\PasswordHasher;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class EmployeeServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testCreateWithoutEmailHasNoLogin(): void
    {
        $saved = $this->employees()->create(1, self::input(email: null));

        assertNull($saved->setupCode);
        assertNull($this->accounts()->findByEmployeeId($saved->id));
        assertSame(2, $this->find($saved->id)->managerId);
    }

    public function testCreateWithEmailIssuesASetupCode(): void
    {
        $now = new DateTimeImmutable('2026-11-10 00:30:00', new DateTimeZone('Europe/Riga'));
        $saved = $this->employeesAt($now)->create(1, self::input(email: ' New.Person@Mail.TEST '));

        $account = $this->accounts()->findByEmployeeId($saved->id);
        assertNotNull($account);
        assertNotNull($saved->setupCode);
        assertSame('new.person@mail.test', $account->email);
        assertNull($account->passwordHash);
        assertTrue($account->isActive);
        assertTrue($this->codeMatches($saved->setupCode, $account->setupCodeHash));
        assertNotNull($account->setupCodeExpiresAt);
        assertSame('2026-11-12 22:30:00', $account->setupCodeExpiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    /**
     * @param array<string, string> $errors
     */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRejected(int $employeeId, EmployeeInput $input, array $errors): void
    {
        try {
            if ($employeeId === 0) {
                $this->employees()->create(1, $input);
            } else {
                $this->employees()->update(1, $employeeId, $input);
            }
            self::fail('Expected the input to be rejected.');
        } catch (ValidationException $e) {
            assertSame($errors, $e->errors);
        }
    }

    /**
     * @return iterable<string, array{int, EmployeeInput, array<string, string>}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'unknown access level' => [0, self::input(accessLevelId: 5), ['accessLevelId' => 'Choose an access level.']];
        yield 'manager is an employee' => [0, self::input(managerId: 4), ['managerId' => 'Choose a manager.']];
        yield 'unknown manager' => [0, self::input(managerId: 9999), ['managerId' => 'Choose a manager.']];
        yield 'email of another login' => [0, self::input(email: 'Admin@mail.test'), ['email' => 'This email is already used by another login.']];
        yield 'manager with employees demoted' => [2, self::input(role: Role::Employee, managerId: 3, email: 'manager@mail.test'), ['role' => "Reassign this manager's employees first."]];
        yield 'employee with active assignments made a manager' => [4, self::input(role: Role::Manager, managerId: null, email: 'employee@mail.test'), ['role' => "Finish or cancel this employee's active assignments first."]];
        yield 'employee with active assignments made an admin' => [4, self::input(role: Role::Admin, managerId: null, email: 'employee@mail.test'), ['role' => "Finish or cancel this employee's active assignments first."]];
        yield 'own role changed' => [1, self::input(role: Role::Manager, managerId: null, email: 'admin@mail.test'), ['role' => "You can't change your own role."]];
        yield 'own login removed' => [1, self::input(role: Role::Admin, managerId: null, email: null), ['email' => "You can't remove your own login."]];
        yield 'own login deactivated' => [1, self::input(role: Role::Admin, managerId: null, email: 'admin@mail.test', loginActive: false), ['loginActive' => "You can't deactivate your own login."]];
    }

    #[DataProvider('birthDates')]
    public function testAgeIsCheckedAgainstTodayInRiga(string $now, string $birthDate, ?string $error): void
    {
        $service = $this->employeesAt(new DateTimeImmutable($now, new DateTimeZone('Europe/Riga')));

        try {
            $saved = $service->create(1, self::input(birthDate: $birthDate));
            assertNull($error);
            assertSame($birthDate, $this->find($saved->id)->birthDate->format('Y-m-d'));
        } catch (ValidationException $e) {
            assertSame(['birthDate' => $error], $e->errors);
        }
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function birthDates(): iterable
    {
        $tooYoung = 'The employee must be at least 16 years old.';
        $tooOld = 'The employee must be at most 100 years old.';

        yield '16 today' => ['2026-10-29 12:00', '2010-10-29', null];
        yield 'a day short of 16' => ['2026-10-29 12:00', '2010-10-30', $tooYoung];
        yield '101 tomorrow' => ['2026-10-29 12:00', '1925-10-30', null];
        yield '101 today' => ['2026-10-29 12:00', '1925-10-29', $tooOld];
        yield '16 after Riga midnight while UTC is a day behind' => ['2026-10-29 00:30', '2010-10-29', null];
        yield '16 tomorrow just before Riga midnight' => ['2026-10-28 23:59', '2010-10-29', $tooYoung];
    }

    public function testManagerIsClearedForManagersAndAdmins(): void
    {
        $saved = $this->employees()->create(1, self::input(role: Role::Manager, managerId: 2));

        assertNull($this->find($saved->id)->managerId);
    }

    public function testManagerWithoutEmployeesCanBeDemoted(): void
    {
        $saved = $this->employees()->create(1, self::input(role: Role::Manager, managerId: null));

        $this->employees()->update(1, $saved->id, self::input(role: Role::Employee, managerId: 3));

        assertSame(Role::Employee, $this->find($saved->id)->role);
        assertSame(3, $this->find($saved->id)->managerId);
    }

    public function testEmployeeWithOnlyDoneOrCancelledAssignmentsCanBecomeAManager(): void
    {
        $this->employees()->update(1, 5, self::input(role: Role::Manager, managerId: null, email: 'employee2@mail.test'));

        assertSame(Role::Manager, $this->find(5)->role);
        assertNull($this->find(5)->managerId);
    }

    public function testEmployeeWithActiveAssignmentsCanStillBeEdited(): void
    {
        $this->employees()->update(1, 4, self::input(firstName: 'Renamed', accessLevelId: 3, managerId: 3, email: 'employee@mail.test'));

        $employee = $this->find(4);
        assertSame('Renamed', $employee->firstName);
        assertSame(3, $employee->accessLevelId);
        assertSame(3, $employee->managerId);
        assertSame(Role::Employee, $employee->role);
    }

    public function testOnlyARoleChangeUpdatesRoleAssignedAt(): void
    {
        $id = $this->employees()->create(1, self::input())->id;
        $this->db()->createCommand("UPDATE EMPLOYEE SET ROLE_ASSIGNED_AT = '2000-01-01T00:00:00' WHERE ID = :id", [':id' => $id])->execute();
        $before = $this->roleAssignedAt($id);

        $this->employees()->update(1, $id, self::input(firstName: 'Renamed'));
        assertSame($before, $this->roleAssignedAt($id));

        $this->employees()->update(1, $id, self::input(role: Role::Manager, managerId: null));
        assertTrue($this->roleAssignedAt($id) > $before);
    }

    public function testAdminCanEditTheirOwnNameAndAccessLevel(): void
    {
        $this->employees()->update(1, 1, self::input(firstName: 'Jānis', lastName: 'Bērziņš-Ozola', accessLevelId: 3, role: Role::Admin, managerId: null, email: 'admin@mail.test'));

        assertSame('Bērziņš-Ozola', $this->find(1)->lastName);
    }

    public function testEmailChangeKeepsThePassword(): void
    {
        $hash = $this->accounts()->findByEmployeeId(4)?->passwordHash;

        $saved = $this->employees()->update(1, 4, self::input(email: 'Martins@Mail.test'));

        $account = $this->accounts()->findByEmployeeId(4);
        assertNotNull($account);
        assertNull($saved->setupCode);
        assertSame('martins@mail.test', $account->email);
        assertSame($hash, $account->passwordHash);
    }

    public function testClearingTheEmailDeletesTheLogin(): void
    {
        $this->employees()->update(1, 4, self::input(email: null));

        assertNull($this->accounts()->findByEmployeeId(4));
    }

    public function testUncheckingLoginActiveDeactivatesTheLogin(): void
    {
        $this->employees()->update(1, 4, self::input(email: 'employee@mail.test', loginActive: false));

        $account = $this->accounts()->findByEmployeeId(4);
        assertNotNull($account);
        assertFalse($account->isActive);
    }

    public function testNewSetupCodeReplacesTheOldOne(): void
    {
        $saved = $this->employees()->create(1, self::input(email: 'pending@mail.test'));
        assertNotNull($saved->setupCode);

        $code = $this->employees()->newSetupCode(1, $saved->id);

        $hash = $this->accounts()->findByEmployeeId($saved->id)?->setupCodeHash;
        assertTrue($this->codeMatches($code, $hash));
        assertFalse($this->codeMatches($saved->setupCode, $hash));
    }

    public function testNewSetupCodeIsRefusedForALoginWithAPassword(): void
    {
        $this->expectException(ValidationException::class);

        $this->employees()->newSetupCode(1, 4);
    }

    public function testDeleteRemovesTheLoginWithTheEmployee(): void
    {
        $this->db()->createCommand('DELETE FROM ASSIGNMENT WHERE EMPLOYEE_ID = 4')->execute();
        $this->employees()->delete(1, 4);

        assertNull($this->employees()->find(1, 4));
        assertNull($this->accounts()->findByEmployeeId(4));
    }

    #[DataProvider('refusedDeletes')]
    public function testDeleteIsRefused(int $id, string $message): void
    {
        try {
            $this->employees()->delete(1, $id);
            self::fail('Expected the delete to be refused.');
        } catch (InUseException $e) {
            assertSame($message, $e->getMessage());
        }
        assertNotNull($this->accounts()->findByEmployeeId($id));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function refusedDeletes(): iterable
    {
        yield 'themselves' => [1, "You can't delete yourself."];
        yield 'manager with employees' => [2, "Reassign this manager's employees first."];
    }

    public function testEmployeeStillReferencedIsNotDeletedAndKeepsTheLogin(): void
    {
        $this->db()->createCommand('CREATE TABLE TEST_EMPLOYEE_REFERENCE (EMPLOYEE_ID INT NOT NULL REFERENCES EMPLOYEE (ID))')->execute();
        $this->db()->createCommand('INSERT INTO TEST_EMPLOYEE_REFERENCE (EMPLOYEE_ID) VALUES (4)')->execute();

        try {
            $this->employees()->delete(1, 4);
            self::fail('Expected the delete to be refused.');
        } catch (InUseException $e) {
            assertSame("This employee still has assignments and can't be deleted.", $e->getMessage());
        }
        assertNotNull($this->accounts()->findByEmployeeId(4));
    }

    /**
     * @return iterable<string, array{int, list<int>|null}>
     */
    public static function listers(): iterable
    {
        yield 'admin' => [1, [9, 23, 45, 54, 27, 75, 37, 1, 48, 31, 61, 69, 32, 34, 14, 46, 35, 22, 67, 70]];
        yield 'manager' => [2, [61, 69, 67, 70, 64, 5, 4, 68, 65, 60, 66, 63, 62, 6, 59]];
        yield 'employee' => [4, null];
        yield 'missing employee' => [999999, null];
    }

    /**
     * @param list<int>|null $expected
     */
    #[DataProvider('listers')]
    public function testListIsScopedToTheActorsRole(int $actorId, ?array $expected): void
    {
        if ($expected === null) {
            $this->expectException(ForbiddenException::class);
        }

        $rows = iterator_to_array($this->employees()->listPaginator($actorId, 'name', '')->read(), false);

        assertSame($expected, array_map(static fn(EmployeeListItem $row): int => $row->id, $rows));
    }

    public function testManagersListsOnlyManagers(): void
    {
        $names = array_map(static fn(Employee $e): string => $e->fullName(), $this->employees()->managers(1, 3));

        assertSame(['Kaspars Eglītis', 'Ilze Kalniņa', 'Gatis Lācis', 'Rūta Petrauskienė', 'Inga Sproģe', 'Margus Tamm'], $names);
    }

    /**
     * @param Closure(EmployeeService): void $call
     */
    #[DataProvider('calls')]
    public function testManagerIsForbidden(Closure $call): void
    {
        $this->expectException(ForbiddenException::class);

        $call($this->employees());
    }

    /**
     * @return iterable<string, array{Closure(EmployeeService): void}>
     */
    public static function calls(): iterable
    {
        yield 'find' => [static function (EmployeeService $s): void {
            $s->find(2, 4);
        }];
        yield 'managers' => [static function (EmployeeService $s): void {
            $s->managers(2, null);
        }];
        yield 'create' => [static function (EmployeeService $s): void {
            $s->create(2, self::input());
        }];
        yield 'update' => [static function (EmployeeService $s): void {
            $s->update(2, 4, self::input());
        }];
        yield 'new setup code' => [static function (EmployeeService $s): void {
            $s->newSetupCode(2, 4);
        }];
        yield 'delete' => [static function (EmployeeService $s): void {
            $s->delete(2, 4);
        }];
    }

    private static function input(
        string $firstName = 'Elīna',
        string $lastName = 'Zvaigzne',
        int $accessLevelId = 2,
        Role $role = Role::Employee,
        ?int $managerId = 2,
        ?string $email = null,
        bool $loginActive = true,
        string $birthDate = '1992-06-15',
    ): EmployeeInput {
        return new EmployeeInput($firstName, $lastName, new DateTimeImmutable($birthDate), $accessLevelId, $role, $managerId, $email, $loginActive);
    }

    private function codeMatches(string $code, ?string $hash): bool
    {
        return $hash !== null && (new PasswordHasher())->validate((new SetupCodeGenerator())->normalize($code), $hash);
    }

    private function roleAssignedAt(int $id): string
    {
        $value = $this->db()->createCommand('SELECT ROLE_ASSIGNED_AT FROM EMPLOYEE WHERE ID = :id', [':id' => $id])->queryScalar();
        self::assertIsString($value);

        return $value;
    }

    private function find(int $id): Employee
    {
        $employee = $this->employees()->find(1, $id);
        assertNotNull($employee);

        return $employee;
    }

    private function employees(): EmployeeService
    {
        return $this->service(EmployeeService::class);
    }

    private function employeesAt(DateTimeImmutable $now): EmployeeService
    {
        return new EmployeeService(
            $this->service(EmployeeRepositoryInterface::class),
            $this->service(AccountRepositoryInterface::class),
            $this->service(AccessLevelRepositoryInterface::class),
            $this->service(AssignmentRepositoryInterface::class),
            $this->service(TransactionRunnerInterface::class),
            $this->service(AccessChecker::class),
            $this->service(AccessScope::class),
            $this->service(PasswordHasher::class),
            $this->service(SetupCodeGenerator::class),
            new FixedClock($now),
        );
    }

    private function accounts(): AccountRepositoryInterface
    {
        return $this->service(AccountRepositoryInterface::class);
    }
}

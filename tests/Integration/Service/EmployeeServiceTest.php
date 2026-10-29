<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Access\ForbiddenException;
use App\Access\Role;
use App\Entity\Employee;
use App\Repository\AccountRepositoryInterface;
use App\Service\EmployeeInput;
use App\Service\EmployeeService;
use App\Service\InUseException;
use App\Service\SetupCodeGenerator;
use App\Service\ValidationException;
use App\Tests\Integration\IntegrationTestCase;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Yiisoft\Security\PasswordHasher;

use function PHPUnit\Framework\assertEqualsWithDelta;
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
        $saved = $this->employees()->create(1, self::input(email: ' New.Person@Mail.TEST '));

        $account = $this->accounts()->findByEmployeeId($saved->id);
        assertNotNull($account);
        assertNotNull($saved->setupCode);
        assertSame('new.person@mail.test', $account->email);
        assertNull($account->passwordHash);
        assertTrue($account->isActive);
        assertTrue($this->codeMatches($saved->setupCode, $account->setupCodeHash));
        assertNotNull($account->setupCodeExpiresAt);
        $expected = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+72 hours');
        assertEqualsWithDelta($expected->getTimestamp(), $account->setupCodeExpiresAt->getTimestamp(), 60);
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
        yield 'own role changed' => [1, self::input(role: Role::Manager, managerId: null, email: 'admin@mail.test'), ['role' => "You can't change your own role."]];
        yield 'own login removed' => [1, self::input(role: Role::Admin, managerId: null, email: null), ['email' => "You can't remove your own login."]];
        yield 'own login deactivated' => [1, self::input(role: Role::Admin, managerId: null, email: 'admin@mail.test', loginActive: false), ['loginActive' => "You can't deactivate your own login."]];
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

    public function testOnlyARoleChangeUpdatesRoleAssignedAt(): void
    {
        $this->db()->createCommand("UPDATE EMPLOYEE SET ROLE_ASSIGNED_AT = '2000-01-01T00:00:00' WHERE ID = 4")->execute();
        $before = $this->roleAssignedAt(4);

        $this->employees()->update(1, 4, self::input(firstName: 'Renamed', email: 'employee@mail.test'));
        assertSame($before, $this->roleAssignedAt(4));

        $this->employees()->update(1, 4, self::input(role: Role::Manager, managerId: null, email: 'employee@mail.test'));
        assertTrue($this->roleAssignedAt(4) > $before);
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
            assertSame("This employee is still in use and can't be deleted.", $e->getMessage());
        }
        assertNotNull($this->accounts()->findByEmployeeId(4));
    }

    public function testManagersListsOnlyManagers(): void
    {
        $names = array_map(static fn(Employee $e): string => $e->fullName(), $this->employees()->managers(1, 3));

        assertSame(['Ilze Kalniņa'], $names);
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
        yield 'list' => [static function (EmployeeService $s): void {
            $s->listPaginator(2, '');
        }];
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
    ): EmployeeInput {
        return new EmployeeInput($firstName, $lastName, new DateTimeImmutable('1992-06-15'), $accessLevelId, $role, $managerId, $email, $loginActive);
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

    private function accounts(): AccountRepositoryInterface
    {
        return $this->service(AccountRepositoryInterface::class);
    }
}

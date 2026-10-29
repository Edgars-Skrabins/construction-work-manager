<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Shared\Access;

use App\Access\AccessChecker;
use App\Access\AccessScope;
use App\Access\Role;
use App\Entity\UserIdentity;
use App\Repository\AccessLevelRepositoryInterface;
use App\Repository\AccountRepositoryInterface;
use App\Repository\AssignmentRepositoryInterface;
use App\Repository\EmployeeRepositoryInterface;
use App\Repository\TransactionRunnerInterface;
use App\Service\EmployeeService;
use App\Service\SetupCodeGenerator;
use App\Tests\FixedClock;
use App\Web\Shared\Access\ViewAccess;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Yiisoft\Auth\IdentityRepositoryInterface;
use Yiisoft\Rbac\ManagerInterface;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\User\CurrentUser;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class ViewAccessTest extends TestCase
{
    public function testChecksAskForTheCurrentUser(): void
    {
        $manager = $this->createMock(ManagerInterface::class);
        $manager->expects($this->exactly(2))
            ->method('userHasPermission')
            ->willReturnCallback(static fn(string $userId): bool => $userId === '2');
        $currentUser = $this->currentUser(2);
        $checker = new AccessChecker($manager);
        $access = new ViewAccess($currentUser, $checker, $this->employeeService($checker, null));

        assertTrue($access->can('site.read'));
        $currentUser->overrideIdentity(new UserIdentity(4, 'Mārtiņš', 'Liepiņš', Role::Employee));
        assertFalse($access->can('site.read'));
    }

    public function testGuestsCanDoNothingWithoutAskingRbac(): void
    {
        $manager = $this->createMock(ManagerInterface::class);
        $manager->expects($this->never())->method('userHasPermission');
        $checker = new AccessChecker($manager);
        $access = new ViewAccess($this->currentUser(null), $checker, $this->employeeService($checker, null));

        assertFalse($access->can('site.read'));
        assertFalse($access->canListEmployees());
    }

    /**
     * @return iterable<string, array{Role, bool, bool}>
     */
    public static function employeeListers(): iterable
    {
        yield 'admin' => [Role::Admin, true, true];
        yield 'manager' => [Role::Manager, true, true];
        yield 'employee with employee.read' => [Role::Employee, true, false];
        yield 'manager without employee.read' => [Role::Manager, false, false];
    }

    #[DataProvider('employeeListers')]
    public function testEmployeeListNeedsTheReadPermissionAndAWiderScopeThanOwn(Role $role, bool $canRead, bool $expected): void
    {
        $manager = $this->createStub(ManagerInterface::class);
        $manager->method('userHasPermission')->willReturn($canRead);
        $checker = new AccessChecker($manager);
        $access = new ViewAccess($this->currentUser(2), $checker, $this->employeeService($checker, $role));

        assertSame($expected, $access->canListEmployees());
    }

    private function employeeService(AccessChecker $checker, ?Role $role): EmployeeService
    {
        $employees = $this->createStub(EmployeeRepositoryInterface::class);
        $employees->method('roleOf')->willReturn($role);

        return new EmployeeService(
            $employees,
            $this->createStub(AccountRepositoryInterface::class),
            $this->createStub(AccessLevelRepositoryInterface::class),
            $this->createStub(AssignmentRepositoryInterface::class),
            $this->createStub(TransactionRunnerInterface::class),
            $checker,
            new AccessScope(),
            new PasswordHasher(),
            new SetupCodeGenerator(),
            new FixedClock(new DateTimeImmutable('2026-10-29')),
        );
    }

    private function currentUser(?int $id): CurrentUser
    {
        $identities = $this->createStub(IdentityRepositoryInterface::class);
        $currentUser = new CurrentUser($identities, $this->createStub(EventDispatcherInterface::class));
        if ($id !== null) {
            $currentUser->overrideIdentity(new UserIdentity($id, 'Ilze', 'Kalniņa', Role::Manager));
        }

        return $currentUser;
    }
}

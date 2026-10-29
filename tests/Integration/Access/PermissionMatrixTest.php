<?php

declare(strict_types=1);

namespace App\Tests\Integration\Access;

use App\Access\AccessChecker;
use App\Access\Permission;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertSame;

final class PermissionMatrixTest extends IntegrationTestCase
{
    private const int ADMIN = 1;
    private const int MANAGER = 2;
    private const int EMPLOYEE = 4;

    #[DataProvider('matrix')]
    public function testRoleHasExactlyItsPermissions(int $employeeId, Permission $permission, bool $expected): void
    {
        assertSame($expected, $this->service(AccessChecker::class)->can($employeeId, $permission));
    }

    /**
     * @return iterable<string, array{int, Permission, bool}>
     */
    public static function matrix(): iterable
    {
        $granted = [
            'admin' => [self::ADMIN, array_values(array_filter(Permission::cases(), static fn(Permission $permission): bool => $permission !== Permission::AssignmentReadOwn))],
            'manager' => [self::MANAGER, [
                Permission::EmployeeRead,
                Permission::SiteRead,
                Permission::WorkItemRead,
                Permission::AssignmentCreate,
                Permission::AssignmentRead,
                Permission::AssignmentUpdate,
                Permission::AssignmentDelete,
            ]],
            'employee' => [self::EMPLOYEE, [
                Permission::EmployeeRead,
                Permission::SiteRead,
                Permission::WorkItemRead,
                Permission::AssignmentRead,
                Permission::AssignmentReadOwn,
            ]],
        ];

        foreach ($granted as $role => [$employeeId, $permissions]) {
            foreach (Permission::cases() as $permission) {
                yield "$role {$permission->value}" => [$employeeId, $permission, in_array($permission, $permissions, true)];
            }
        }
    }
}

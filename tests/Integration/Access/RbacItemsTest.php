<?php

declare(strict_types=1);

namespace App\Tests\Integration\Access;

use App\Access\Permission;
use App\Access\Role;
use App\Tests\Integration\IntegrationTestCase;

use function PHPUnit\Framework\assertSame;

final class RbacItemsTest extends IntegrationTestCase
{
    public function testRolesMatchTheRoleEnum(): void
    {
        assertSame($this->sorted(array_column(Role::cases(), 'value')), $this->itemNames('role'));
    }

    public function testPermissionsMatchThePermissionEnum(): void
    {
        assertSame($this->sorted(array_column(Permission::cases(), 'value')), $this->itemNames('permission'));
    }

    public function testRolesDoNotInheritOtherRoles(): void
    {
        $links = (int) $this->db()->createCommand(
            "SELECT COUNT(*) FROM RBAC_ITEM_CHILD c JOIN RBAC_ITEM i ON i.name = c.child WHERE i.type = N'role'",
        )->queryScalar();

        assertSame(0, $links);
    }

    /**
     * @return list<string>
     */
    private function itemNames(string $type): array
    {
        /** @var list<string> $names */
        $names = $this->db()->createCommand('SELECT name FROM RBAC_ITEM WHERE type = :type', [':type' => $type])->queryColumn();

        return $this->sorted($names);
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}

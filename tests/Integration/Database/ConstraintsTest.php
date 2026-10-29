<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Yiisoft\Db\Exception\Exception as DbException;

use function PHPUnit\Framework\assertStringContainsString;

final class ConstraintsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    #[DataProvider('invalidRows')]
    public function testInvalidRowIsRejected(string $sql, string $constraint): void
    {
        $this->assertRejected($sql, $constraint);
    }

    public function testRoleInUseCannotBeDeleted(): void
    {
        $this->db()->createCommand("DELETE FROM RBAC_ITEM_CHILD WHERE parent = N'manager'")->execute();

        $this->assertRejected("DELETE FROM RBAC_ITEM WHERE name = N'manager'", 'FK_EMPLOYEE_ROLE');
    }

    public function testManagerWithEmployeesCannotBeDeleted(): void
    {
        $this->db()->createCommand('DELETE FROM ACCOUNT WHERE EMPLOYEE_ID = 2')->execute();

        $this->assertRejected('DELETE FROM EMPLOYEE WHERE ID = 2', 'FK_EMPLOYEE_MANAGER_ID');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRows(): iterable
    {
        $employee = "INSERT INTO EMPLOYEE (FIRST_NAME, LAST_NAME, BIRTH_DATE, ACCESS_LEVEL_ID, ROLE, MANAGER_ID) VALUES (N'Test', N'Person', '1990-01-01', %s, %s, %s)";
        $site = "INSERT INTO CONSTRUCTION_SITE (NAME, STREET, CITY, COUNTRY, AREA_M2, REQUIRED_ACCESS_LEVEL_ID) VALUES (%s, N'Street 1', N'Rīga', N'Latvia', %s, 1)";

        yield 'unknown role' => [sprintf($employee, '1', "N'director'", 'NULL'), 'FK_EMPLOYEE_ROLE'];
        yield 'permission as role' => [sprintf($employee, '1', "N'site.read'", 'NULL'), 'FK_EMPLOYEE_ROLE'];
        yield 'unknown access level' => [sprintf($employee, '5', "N'employee'", 'NULL'), 'FK_EMPLOYEE_ACCESS_LEVEL_ID'];
        yield 'missing manager' => [sprintf($employee, '1', "N'employee'", '9999'), 'FK_EMPLOYEE_MANAGER_ID'];
        yield 'own manager' => ["UPDATE EMPLOYEE SET MANAGER_ID = ID WHERE ID = 4", 'CK_EMPLOYEE_NOT_OWN_MANAGER'];
        yield 'zero area' => [sprintf($site, "N'Test site'", '0'), 'CK_CONSTRUCTION_SITE_AREA_M2'];
        yield 'duplicate site name' => [sprintf($site, "N'Daugavpils School Renovation'", '100'), 'UQ_CONSTRUCTION_SITE_NAME'];
        yield 'unknown site access level' => [
            "INSERT INTO CONSTRUCTION_SITE (NAME, STREET, CITY, COUNTRY, AREA_M2, REQUIRED_ACCESS_LEVEL_ID) VALUES (N'Other site', N'Street 1', N'Rīga', N'Latvia', 100, 0)",
            'FK_CONSTRUCTION_SITE_REQUIRED_ACCESS_LEVEL_ID',
        ];
        yield 'duplicate work item name' => ["INSERT INTO WORK_ITEM (NAME) VALUES (N'Welding')", 'UQ_WORK_ITEM_NAME'];
        yield 'referenced access level deleted' => ['DELETE FROM ACCESS_LEVEL WHERE ID = 1', 'FK_EMPLOYEE_ACCESS_LEVEL_ID'];
        yield 'role item type other than role' => ["UPDATE EMPLOYEE SET ROLE_ITEM_TYPE = N'permission' WHERE ID = 4", 'CK_EMPLOYEE_ROLE_ITEM_TYPE'];
        yield 'unknown item type' => [
            "INSERT INTO RBAC_ITEM (name, type, created_at, updated_at) VALUES (N'test.item', N'group', 0, 0)",
            'CK_RBAC_ITEM_TYPE',
        ];
    }

    private function assertRejected(string $sql, string $constraint): void
    {
        try {
            $this->db()->createCommand($sql)->execute();
            self::fail("Expected $constraint to reject the statement.");
        } catch (DbException $e) {
            assertStringContainsString($constraint, $e->getMessage());
        }
    }
}

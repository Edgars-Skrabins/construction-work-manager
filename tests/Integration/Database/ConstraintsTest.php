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

    public function testAccessLevelOfEmployeesCannotBeDeleted(): void
    {
        $this->db()->createCommand('UPDATE CONSTRUCTION_SITE SET REQUIRED_ACCESS_LEVEL_ID = 2 WHERE REQUIRED_ACCESS_LEVEL_ID = 1')->execute();

        $this->assertRejected('DELETE FROM ACCESS_LEVEL WHERE ID = 1', 'FK_EMPLOYEE_ACCESS_LEVEL_ID');
    }

    public function testAccessLevelRequiredBySitesCannotBeDeleted(): void
    {
        $this->db()->createCommand('UPDATE EMPLOYEE SET ACCESS_LEVEL_ID = 2 WHERE ACCESS_LEVEL_ID = 1')->execute();

        $this->assertRejected('DELETE FROM ACCESS_LEVEL WHERE ID = 1', 'FK_CONSTRUCTION_SITE_REQUIRED_ACCESS_LEVEL_ID');
    }

    public function testManagerWithEmployeesCannotBeDeleted(): void
    {
        $this->db()->createCommand('DELETE FROM ACCOUNT WHERE EMPLOYEE_ID = 2')->execute();
        $this->db()->createCommand('DELETE FROM ASSIGNMENT WHERE CREATED_BY_ID = 2')->execute();

        $this->assertRejected('DELETE FROM EMPLOYEE WHERE ID = 2', 'FK_EMPLOYEE_MANAGER_ID');
    }

    public function testEmployeeWithAssignmentsCannotBeDeleted(): void
    {
        $this->db()->createCommand('DELETE FROM ACCOUNT WHERE EMPLOYEE_ID = 4')->execute();

        $this->assertRejected('DELETE FROM EMPLOYEE WHERE ID = 4', 'FK_ASSIGNMENT_EMPLOYEE_ID');
    }

    public function testCreatorOfAssignmentsCannotBeDeleted(): void
    {
        $this->db()->createCommand('DELETE FROM ACCOUNT WHERE EMPLOYEE_ID = 1')->execute();
        $this->db()->createCommand('INSERT INTO ASSIGNMENT (EMPLOYEE_ID, WORK_ITEM_ID, CONSTRUCTION_SITE_ID, START_DATE, END_DATE, CREATED_BY_ID) VALUES (7, 1, 1, \'2026-11-01\', \'2026-11-09\', 1)')->execute();

        $this->assertRejected('DELETE FROM EMPLOYEE WHERE ID = 1', 'FK_ASSIGNMENT_CREATED_BY_ID');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRows(): iterable
    {
        $employee = "INSERT INTO EMPLOYEE (FIRST_NAME, LAST_NAME, BIRTH_DATE, ACCESS_LEVEL_ID, ROLE, MANAGER_ID) VALUES (N'Test', N'Person', '1990-01-01', %s, %s, %s)";
        $site = "INSERT INTO CONSTRUCTION_SITE (CODE, NAME, STREET, CITY, COUNTRY, AREA_M2, REQUIRED_ACCESS_LEVEL_ID) VALUES (%s, %s, N'Street 1', N'Rīga', N'Latvia', %s, 1)";

        yield 'unknown role' => [sprintf($employee, '1', "N'director'", 'NULL'), 'FK_EMPLOYEE_ROLE'];
        yield 'permission as role' => [sprintf($employee, '1', "N'site.read'", 'NULL'), 'FK_EMPLOYEE_ROLE'];
        yield 'unknown access level' => [sprintf($employee, '5', "N'employee'", 'NULL'), 'FK_EMPLOYEE_ACCESS_LEVEL_ID'];
        yield 'missing manager' => [sprintf($employee, '1', "N'employee'", '9999'), 'FK_EMPLOYEE_MANAGER_ID'];
        yield 'own manager' => ["UPDATE EMPLOYEE SET MANAGER_ID = ID WHERE ID = 4", 'CK_EMPLOYEE_NOT_OWN_MANAGER'];
        yield 'zero area' => [sprintf($site, "N'TST-001'", "N'Test site'", '0'), 'CK_CONSTRUCTION_SITE_AREA_M2'];
        yield 'duplicate site name' => [sprintf($site, "N'TST-001'", "N'Daugavpils School Renovation'", '100'), 'UQ_CONSTRUCTION_SITE_NAME'];
        yield 'duplicate site code' => [sprintf($site, "N'DGP-SCH-01'", "N'Test site'", '100'), 'UQ_CONSTRUCTION_SITE_CODE'];
        yield 'lowercase site code' => [sprintf($site, "N'tst-001'", "N'Test site'", '100'), 'CK_CONSTRUCTION_SITE_CODE'];
        yield 'short site code' => [sprintf($site, "N'T1'", "N'Test site'", '100'), 'CK_CONSTRUCTION_SITE_CODE'];
        yield 'site code starting with a hyphen' => [sprintf($site, "N'-TST-001'", "N'Test site'", '100'), 'CK_CONSTRUCTION_SITE_CODE'];
        yield 'site code with an underscore' => [sprintf($site, "N'TST_001'", "N'Test site'", '100'), 'CK_CONSTRUCTION_SITE_CODE'];
        yield 'site without a code' => [
            "INSERT INTO CONSTRUCTION_SITE (NAME, STREET, CITY, COUNTRY, AREA_M2, REQUIRED_ACCESS_LEVEL_ID) VALUES (N'Test site', N'Street 1', N'Rīga', N'Latvia', 100, 1)",
            'CODE',
        ];
        yield 'unknown site access level' => [
            "INSERT INTO CONSTRUCTION_SITE (CODE, NAME, STREET, CITY, COUNTRY, AREA_M2, REQUIRED_ACCESS_LEVEL_ID) VALUES (N'TST-001', N'Other site', N'Street 1', N'Rīga', N'Latvia', 100, 0)",
            'FK_CONSTRUCTION_SITE_REQUIRED_ACCESS_LEVEL_ID',
        ];
        yield 'duplicate work item name' => ["INSERT INTO WORK_ITEM (NAME) VALUES (N'Welding')", 'UQ_WORK_ITEM_NAME'];
        yield 'role item type other than role' => ["UPDATE EMPLOYEE SET ROLE_ITEM_TYPE = N'permission' WHERE ID = 4", 'CK_EMPLOYEE_ROLE_ITEM_TYPE'];
        $assignment = "INSERT INTO ASSIGNMENT (EMPLOYEE_ID, WORK_ITEM_ID, CONSTRUCTION_SITE_ID, START_DATE, END_DATE, STATUS, CREATED_BY_ID) VALUES (%s, %s, %s, %s, %s, %s, %s)";

        yield 'assignment ends before it starts' => [sprintf($assignment, '4', '1', '1', "'2026-11-10'", "'2026-11-09'", "N'active'", '2'), 'CK_ASSIGNMENT_PERIOD'];
        yield 'unknown assignment status' => [sprintf($assignment, '4', '1', '1', "'2026-11-01'", "'2026-11-09'", "N'planned'", '2'), 'CK_ASSIGNMENT_STATUS'];
        yield 'assignment for a missing employee' => [sprintf($assignment, '9999', '1', '1', "'2026-11-01'", "'2026-11-09'", "N'active'", '2'), 'FK_ASSIGNMENT_EMPLOYEE_ID'];
        yield 'assignment of a missing work item' => [sprintf($assignment, '4', '9999', '1', "'2026-11-01'", "'2026-11-09'", "N'active'", '2'), 'FK_ASSIGNMENT_WORK_ITEM_ID'];
        yield 'assignment at a missing site' => [sprintf($assignment, '4', '1', '9999', "'2026-11-01'", "'2026-11-09'", "N'active'", '2'), 'FK_ASSIGNMENT_CONSTRUCTION_SITE_ID'];
        yield 'assignment created by a missing employee' => [sprintf($assignment, '4', '1', '1', "'2026-11-01'", "'2026-11-09'", "N'active'", '9999'), 'FK_ASSIGNMENT_CREATED_BY_ID'];
        yield 'referenced work item deleted' => ['DELETE FROM WORK_ITEM WHERE ID = 1', 'FK_ASSIGNMENT_WORK_ITEM_ID'];
        yield 'referenced site deleted' => ['DELETE FROM CONSTRUCTION_SITE WHERE ID = 4', 'FK_ASSIGNMENT_CONSTRUCTION_SITE_ID'];
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

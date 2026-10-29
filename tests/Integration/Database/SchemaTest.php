<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

final class SchemaTest extends IntegrationTestCase
{
    #[DataProvider('tables')]
    public function testTableExists(string $table): void
    {
        assertNotNull($this->db()->getTableSchema($table, true));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tables(): iterable
    {
        foreach (['ACCESS_LEVEL', 'EMPLOYEE', 'CONSTRUCTION_SITE', 'WORK_ITEM', 'RBAC_ITEM', 'RBAC_ITEM_CHILD', 'ASSIGNMENT'] as $table) {
            yield $table => [$table];
        }
    }

    public function testAccessLevelsAreTheFourDefinedLevels(): void
    {
        $names = $this->db()->createCommand('SELECT NAME FROM ACCESS_LEVEL ORDER BY ID')->queryColumn();

        assertSame(['General', 'Restricted', 'Critical infrastructure', 'Classified'], $names);
    }

    #[DataProvider('seededTables')]
    public function testTableHasAtLeastThreeSeedRows(string $table): void
    {
        $count = (int) $this->db()->createCommand("SELECT COUNT(*) FROM $table")->queryScalar();

        assertGreaterThanOrEqual(3, $count);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function seededTables(): iterable
    {
        foreach (['EMPLOYEE', 'CONSTRUCTION_SITE', 'WORK_ITEM'] as $table) {
            yield $table => [$table];
        }
    }

    public function testDiacriticsAreStoredUnchanged(): void
    {
        $name = $this->db()->createCommand('SELECT LAST_NAME FROM EMPLOYEE WHERE ID = 1')->queryScalar();

        assertSame('Bērziņš', $name);
    }

    public function testEveryEmployeeOfAManagerReportsToAManager(): void
    {
        $invalid = (int) $this->db()->createCommand(
            "SELECT COUNT(*) FROM EMPLOYEE e JOIN EMPLOYEE m ON m.ID = e.MANAGER_ID WHERE m.ROLE <> N'manager'",
        )->queryScalar();

        assertSame(0, $invalid);
    }

    public function testSeededSitesHaveTheirCodes(): void
    {
        $codes = $this->db()->createCommand('SELECT CODE FROM CONSTRUCTION_SITE WHERE ID <= 4 ORDER BY ID')->queryColumn();
        $fallbacks = (int) $this->db()->createCommand("SELECT COUNT(*) FROM CONSTRUCTION_SITE WHERE CODE LIKE N'SITE-%'")->queryScalar();

        assertSame(['RIX-AGK-01', 'DGP-SCH-01', 'RIX-TEC-01', 'ADZ-DEF-01'], $codes);
        assertSame(0, $fallbacks);
    }

    public function testRoleAssignedAtIsBackfilledFromCreatedAt(): void
    {
        $mismatched = (int) $this->db()->createCommand(
            'SELECT COUNT(*) FROM EMPLOYEE WHERE ROLE_ASSIGNED_AT <> CREATED_AT',
        )->queryScalar();

        assertSame(0, $mismatched);
    }

    public function testSeedAssignmentsCoverEveryStoredStatus(): void
    {
        $rows = $this->db()->createCommand(
            "SELECT CONCAT(ID, N' ', STATUS, N' ', CREATED_BY_ID, N' ', DATEDIFF(DAY, d.SEEDED_ON, START_DATE), N' ', DATEDIFF(DAY, d.SEEDED_ON, END_DATE)) FROM ASSIGNMENT CROSS APPLY (SELECT CAST(CREATED_AT AT TIME ZONE 'UTC' AT TIME ZONE 'FLE Standard Time' AS DATE) AS SEEDED_ON) d ORDER BY ID",
        )->queryColumn();

        assertSame(
            ['1 active 2 -7 14', '2 active 2 7 30', '3 active 2 -30 -3', '4 active 3 -2 20', '5 done 3 -40 -10', '6 cancelled 3 3 10'],
            $rows,
        );
    }

    public function testSeedAssignmentsRespectAccessLevelsAndManagers(): void
    {
        $invalid = (int) $this->db()->createCommand(
            'SELECT COUNT(*) FROM ASSIGNMENT a JOIN EMPLOYEE e ON e.ID = a.EMPLOYEE_ID JOIN CONSTRUCTION_SITE s ON s.ID = a.CONSTRUCTION_SITE_ID WHERE e.ACCESS_LEVEL_ID < s.REQUIRED_ACCESS_LEVEL_ID OR e.MANAGER_ID <> a.CREATED_BY_ID',
        )->queryScalar();

        assertSame(0, $invalid);
    }
}

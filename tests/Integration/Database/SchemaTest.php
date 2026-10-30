<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertSame;

final class SchemaTest extends IntegrationTestCase
{
    public function testAccessLevelsAreTheFourDefinedLevels(): void
    {
        $names = $this->db()->createCommand('SELECT NAME FROM ACCESS_LEVEL ORDER BY ID')->queryColumn();

        assertSame(['General', 'Restricted', 'Critical infrastructure', 'Classified'], $names);
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
        $codes = $this->db()->createCommand('SELECT CODE FROM CONSTRUCTION_SITE ORDER BY ID')->queryColumn();
        $fallbacks = (int) $this->db()->createCommand("SELECT COUNT(*) FROM CONSTRUCTION_SITE WHERE CODE LIKE N'SITE-%'")->queryScalar();

        assertSame(
            ['RIX-AGK-01', 'DGP-SCH-01', 'RIX-TEC-01', 'ADZ-DEF-01', 'LPX-PRT-01', 'JLG-LIB-01', 'VLM-HSP-01', 'VNT-WTP-01', 'VNO-OFC-01', 'TLL-DCN-01', 'KUN-LOG-01'],
            $codes,
        );
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
            "SELECT CONCAT(ID, N' ', STATUS, N' ', CREATED_BY_ID, N' ', DATEDIFF(DAY, d.SEEDED_ON, START_DATE), N' ', DATEDIFF(DAY, d.SEEDED_ON, END_DATE)) FROM ASSIGNMENT CROSS APPLY (SELECT CAST(CREATED_AT AT TIME ZONE 'UTC' AT TIME ZONE 'FLE Standard Time' AS DATE) AS SEEDED_ON) d WHERE ID <= 6 ORDER BY ID",
        )->queryColumn();

        assertSame(
            ['1 active 2 -7 14', '2 cancelled 2 7 30', '3 done 2 -30 -3', '4 active 3 -2 20', '5 done 3 -40 -10', '6 cancelled 3 3 10'],
            $rows,
        );
    }

    public function testSeedAssignmentsAreCreatedByTheEmployeesManager(): void
    {
        $invalid = (int) $this->db()->createCommand(
            'SELECT COUNT(*) FROM ASSIGNMENT a JOIN EMPLOYEE e ON e.ID = a.EMPLOYEE_ID WHERE e.MANAGER_ID IS NULL OR e.MANAGER_ID <> a.CREATED_BY_ID',
        )->queryScalar();

        assertSame(0, $invalid);
    }

    public function testOnlyTheFlaggedSeedAssignmentsAreBelowTheSiteAccessLevel(): void
    {
        $rows = $this->db()->createCommand(
            "SELECT CONCAT(a.ID, N' ', a.STATUS) FROM ASSIGNMENT a JOIN EMPLOYEE e ON e.ID = a.EMPLOYEE_ID JOIN CONSTRUCTION_SITE s ON s.ID = a.CONSTRUCTION_SITE_ID WHERE e.ACCESS_LEVEL_ID < s.REQUIRED_ACCESS_LEVEL_ID ORDER BY a.ID",
        )->queryColumn();

        assertSame(['3 done', '12 active', '13 active', '33 active', '34 active'], $rows);
    }

    public function testSeedAssignmentsNeverOverlapForTheSameWorkAtTheSameSite(): void
    {
        $overlapping = (int) $this->db()->createCommand(
            "SELECT COUNT(*) FROM ASSIGNMENT a JOIN ASSIGNMENT b ON b.ID > a.ID AND b.EMPLOYEE_ID = a.EMPLOYEE_ID AND b.WORK_ITEM_ID = a.WORK_ITEM_ID AND b.CONSTRUCTION_SITE_ID = a.CONSTRUCTION_SITE_ID AND a.START_DATE <= b.END_DATE AND a.END_DATE >= b.START_DATE WHERE a.STATUS = N'active' AND b.STATUS = N'active'",
        )->queryScalar();

        assertSame(0, $overlapping);
    }

    public function testSeedSizes(): void
    {
        $sizes = $this->db()->createCommand(
            "SELECT CONCAT(ROLE, N' ', COUNT(*)) FROM EMPLOYEE GROUP BY ROLE UNION ALL SELECT CONCAT(N'site ', COUNT(*)) FROM CONSTRUCTION_SITE UNION ALL SELECT CONCAT(N'work item ', COUNT(*)) FROM WORK_ITEM UNION ALL SELECT CONCAT(N'assignment ', COUNT(*)) FROM ASSIGNMENT UNION ALL SELECT CONCAT(N'account ', COUNT(*)) FROM ACCOUNT",
        )->queryColumn();
        sort($sizes);

        assertSame(['account 7', 'admin 3', 'assignment 177', 'employee 70', 'manager 7', 'site 11', 'work item 25'], $sizes);
    }

    public function testSeedBirthDatesKeepTheAgeRule(): void
    {
        $invalid = (int) $this->db()->createCommand(
            "SELECT COUNT(*) FROM EMPLOYEE CROSS APPLY (SELECT CAST(SYSDATETIMEOFFSET() AT TIME ZONE 'FLE Standard Time' AS DATE) AS TODAY) d WHERE BIRTH_DATE > DATEADD(YEAR, -16, d.TODAY) OR BIRTH_DATE <= DATEADD(YEAR, -101, d.TODAY)",
        )->queryScalar();

        assertSame(0, $invalid);
    }

    #[DataProvider('seedCases')]
    public function testSeedCoversEveryCase(string $sql, int $minimum): void
    {
        $count = (int) $this->db()->createCommand($sql)->queryScalar();

        assertGreaterThanOrEqual($minimum, $count);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function seedCases(): iterable
    {
        $assignments = "SELECT COUNT(*) FROM ASSIGNMENT a CROSS APPLY (SELECT CAST(a.CREATED_AT AT TIME ZONE 'UTC' AT TIME ZONE 'FLE Standard Time' AS DATE) AS SEEDED_ON) s CROSS APPLY (SELECT DATEDIFF(DAY, s.SEEDED_ON, a.START_DATE) AS START_DAY, DATEDIFF(DAY, s.SEEDED_ON, a.END_DATE) AS END_DAY) d WHERE ";
        $inProgress = "a.STATUS = N'active' AND d.START_DAY <= 0 AND d.END_DAY >= 0";
        $grouped = "SELECT COUNT(*) FROM (SELECT a.%s FROM ASSIGNMENT a CROSS APPLY (SELECT CAST(a.CREATED_AT AT TIME ZONE 'UTC' AT TIME ZONE 'FLE Standard Time' AS DATE) AS SEEDED_ON) s CROSS APPLY (SELECT DATEDIFF(DAY, s.SEEDED_ON, a.START_DATE) AS START_DAY, DATEDIFF(DAY, s.SEEDED_ON, a.END_DATE) AS END_DAY) d WHERE $inProgress GROUP BY a.%1\$s HAVING COUNT(DISTINCT a.%s) >= %d) g";

        yield 'planned' => [$assignments . "a.STATUS = N'active' AND d.START_DAY > 0", 10];
        yield 'in progress' => [$assignments . $inProgress, 20];
        yield 'overdue' => [$assignments . "a.STATUS = N'active' AND d.END_DAY < 0", 5];
        yield 'done' => [$assignments . "a.STATUS = N'done'", 20];
        yield 'cancelled' => [$assignments . "a.STATUS = N'cancelled'", 10];
        yield 'starts today' => [$assignments . "a.STATUS = N'active' AND d.START_DAY = 0 AND d.END_DAY > 0", 1];
        yield 'ends today' => [$assignments . "a.STATUS = N'active' AND d.START_DAY < 0 AND d.END_DAY = 0", 1];
        yield 'today only' => [$assignments . "a.STATUS = N'active' AND d.START_DAY = 0 AND d.END_DAY = 0", 1];
        yield 'one day' => [$assignments . 'd.START_DAY = d.END_DAY', 3];
        yield 'longer than 300 days' => [$assignments . 'd.END_DAY - d.START_DAY > 300', 1];
        yield 'employee in progress at three sites' => [sprintf($grouped, 'EMPLOYEE_ID', 'CONSTRUCTION_SITE_ID', 3), 1];
        yield 'sites with five employees in progress' => [sprintf($grouped, 'CONSTRUCTION_SITE_ID', 'EMPLOYEE_ID', 5), 3];
        yield 'same work at the same site as done, active and cancelled' => ['SELECT COUNT(*) FROM (SELECT EMPLOYEE_ID FROM ASSIGNMENT GROUP BY EMPLOYEE_ID, WORK_ITEM_ID, CONSTRUCTION_SITE_ID HAVING COUNT(DISTINCT STATUS) = 3) g', 1];
        yield 'employees without assignments' => ["SELECT COUNT(*) FROM EMPLOYEE e WHERE e.ROLE = N'employee' AND NOT EXISTS (SELECT 1 FROM ASSIGNMENT a WHERE a.EMPLOYEE_ID = e.ID)", 5];
        yield 'manager without subordinates' => ["SELECT COUNT(*) FROM EMPLOYEE m WHERE m.ROLE = N'manager' AND NOT EXISTS (SELECT 1 FROM EMPLOYEE e WHERE e.MANAGER_ID = m.ID)", 1];
        yield 'site without assignments' => ['SELECT COUNT(*) FROM CONSTRUCTION_SITE s WHERE NOT EXISTS (SELECT 1 FROM ASSIGNMENT a WHERE a.CONSTRUCTION_SITE_ID = s.ID)', 1];
        yield 'work item without assignments' => ['SELECT COUNT(*) FROM WORK_ITEM w WHERE NOT EXISTS (SELECT 1 FROM ASSIGNMENT a WHERE a.WORK_ITEM_ID = w.ID)', 1];
        yield 'every access level among employees' => ["SELECT COUNT(DISTINCT ACCESS_LEVEL_ID) FROM EMPLOYEE WHERE ROLE = N'employee'", 4];
    }
}

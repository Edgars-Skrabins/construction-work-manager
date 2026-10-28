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
        foreach (['ACCESS_LEVEL', 'EMPLOYEE', 'CONSTRUCTION_SITE', 'WORK_ITEM'] as $table) {
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
}

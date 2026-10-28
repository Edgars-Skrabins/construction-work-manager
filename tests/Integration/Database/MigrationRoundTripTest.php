<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Tests\Integration\IntegrationTestCase;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class MigrationRoundTripTest extends IntegrationTestCase
{
    private const array TABLES = ['WORK_ITEM', 'CONSTRUCTION_SITE', 'EMPLOYEE', 'ACCESS_LEVEL'];

    public function testAllMigrationsRevertAndReapply(): void
    {
        $this->console('migrate:down --all');

        foreach (self::TABLES as $table) {
            assertNull($this->db()->getTableSchema($table, true), "$table should be dropped.");
        }

        $this->console('migrate:up');

        foreach (self::TABLES as $table) {
            assertNotNull($this->db()->getTableSchema($table, true), "$table should be recreated.");
        }
        assertSame('8', (string) $this->db()->createCommand('SELECT COUNT(*) FROM EMPLOYEE')->queryScalar());
    }

    private function console(string $command): void
    {
        $root = dirname(__DIR__, 3);
        exec(sprintf('php %s %s --no-interaction 2>&1', escapeshellarg($root . '/yii'), $command), $output, $exitCode);

        assertSame(0, $exitCode, implode("\n", $output));
    }
}

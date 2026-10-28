<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Tests\Integration\IntegrationTestCase;

use function PHPUnit\Framework\assertNotNull;

final class MigrationPipelineTest extends IntegrationTestCase
{
    public function testMigrationHistoryTableExistsInTestDatabase(): void
    {
        assertNotNull($this->db()->getTableSchema('migration', true));
    }
}

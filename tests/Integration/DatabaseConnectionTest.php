<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Environment;

use function PHPUnit\Framework\assertSame;

final class DatabaseConnectionTest extends IntegrationTestCase
{
    public function testConnectionAnswersQueries(): void
    {
        assertSame('1', (string) $this->db()->createCommand('SELECT 1')->queryScalar());
    }

    public function testTestEnvironmentUsesTestDatabase(): void
    {
        assertSame(Environment::dbTestName(), $this->db()->createCommand('SELECT DB_NAME()')->queryScalar());
        assertSame('construction_work_manager_test', Environment::dbTestName());
    }
}

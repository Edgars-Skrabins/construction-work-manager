<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Environment;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function PHPUnit\Framework\assertSame;

final class DatabaseConnectionTest extends TestCase
{
    private ConnectionInterface $db;

    protected function setUp(): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );

        $db = $runner->getContainer()->get(ConnectionInterface::class);
        self::assertInstanceOf(ConnectionInterface::class, $db);
        $this->db = $db;
    }

    public function testConnectionAnswersQueries(): void
    {
        assertSame('1', (string) $this->db->createCommand('SELECT 1')->queryScalar());
    }

    public function testTestEnvironmentUsesTestDatabase(): void
    {
        assertSame(Environment::dbTestName(), $this->db->createCommand('SELECT DB_NAME()')->queryScalar());
        assertSame('construction_work_manager_test', Environment::dbTestName());
    }
}

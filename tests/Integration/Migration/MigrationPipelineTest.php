<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Environment;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function PHPUnit\Framework\assertNotNull;

final class MigrationPipelineTest extends TestCase
{
    public function testMigrationHistoryTableExistsInTestDatabase(): void
    {
        $db = (new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 3),
            environment: Environment::appEnv(),
        ))->getContainer()->get(ConnectionInterface::class);
        self::assertInstanceOf(ConnectionInterface::class, $db);

        assertNotNull($db->getTableSchema('migration', true));
    }
}

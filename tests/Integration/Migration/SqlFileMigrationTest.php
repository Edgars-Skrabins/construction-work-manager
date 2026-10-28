<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Migration\SqlBatchSplitter;
use App\Migration\SqlFileMigration;
use App\Tests\Integration\IntegrationTestCase;
use RuntimeException;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class SqlFileMigrationTest extends IntegrationTestCase
{
    private ConnectionInterface $db;
    private Aliases $aliases;
    private MigrationBuilder $builder;

    protected function setUp(): void
    {
        $this->db = $this->db();
        $this->aliases = $this->service(Aliases::class);
        $this->builder = new MigrationBuilder($this->db, new NullMigrationInformer());
    }

    protected function tearDown(): void
    {
        $this->db->createCommand("DROP VIEW IF EXISTS TEST_ITEM_NAME")->execute();
        $this->db->createCommand("DROP TABLE IF EXISTS TEST_ITEM")->execute();
        $this->db->createCommand("DROP TABLE IF EXISTS TEST_BROKEN")->execute();
    }

    public function testUpRunsEveryBatchAndDownRevertsThem(): void
    {
        $migration = $this->migration('001_create_test_item');

        $this->inTransaction(fn() => $migration->up($this->builder));

        $this->db->createCommand("INSERT INTO TEST_ITEM (NAME) VALUES (N'Bērziņš')")->execute();
        assertSame('Bērziņš', $this->db->createCommand('SELECT NAME FROM TEST_ITEM_NAME')->queryScalar());

        $this->inTransaction(fn() => $migration->down($this->builder));

        assertFalse($this->objectExists('TEST_ITEM'));
        assertFalse($this->objectExists('TEST_ITEM_NAME'));
    }

    public function testFailingBatchIsLocatedAndRolledBack(): void
    {
        $migration = $this->migration('002_failing_script');

        try {
            $this->inTransaction(fn() => $migration->up($this->builder));
            self::fail('Expected the failing script to throw.');
        } catch (RuntimeException $e) {
            assertTrue(str_starts_with(
                $e->getMessage(),
                'tests/Integration/Migration/Fixtures/002_failing_script.sql, batch 2 (line 6): ',
            ));
            assertTrue(str_contains($e->getMessage(), 'MISSING_COLUMN'));
        }

        assertFalse($this->objectExists('TEST_BROKEN'));
    }

    public function testMissingDownScriptIsReported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'SQL script tests/Integration/Migration/Fixtures/down/003_without_down_script.sql does not exist.',
        );

        $this->migration('003_without_down_script')->down($this->builder);
    }

    private function migration(string $script): SqlFileMigration
    {
        return new class ($this->aliases, new SqlBatchSplitter(), $script) extends SqlFileMigration {
            public function __construct(Aliases $aliases, SqlBatchSplitter $splitter, private readonly string $name)
            {
                parent::__construct($aliases, $splitter);
            }

            protected function script(): string
            {
                return $this->name;
            }

            protected function directory(): string
            {
                return __DIR__ . '/Fixtures';
            }
        };
    }

    private function inTransaction(callable $operation): void
    {
        $this->db->transaction(static function () use ($operation): void {
            $operation();
        });
    }

    private function objectExists(string $name): bool
    {
        return $this->db->createCommand('SELECT OBJECT_ID(:name)', [':name' => $name])->queryScalar() !== null;
    }
}

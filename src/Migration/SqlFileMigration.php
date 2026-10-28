<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Throwable;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

use function file_get_contents;
use function is_file;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

abstract class SqlFileMigration implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function __construct(
        private readonly Aliases $aliases,
        private readonly SqlBatchSplitter $splitter,
    ) {}

    final public function up(MigrationBuilder $b): void
    {
        $this->run($b, $this->directory() . '/' . $this->script() . '.sql');
    }

    final public function down(MigrationBuilder $b): void
    {
        $this->run($b, $this->directory() . '/down/' . $this->script() . '.sql');
    }

    abstract protected function script(): string;

    protected function directory(): string
    {
        return $this->aliases->get('@sql');
    }

    private function run(MigrationBuilder $b, string $path): void
    {
        $file = $this->relative($path);

        if (!is_file($path)) {
            throw new RuntimeException(sprintf('SQL script %s does not exist.', $file));
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException(sprintf('SQL script %s could not be read.', $file));
        }

        foreach ($this->splitter->split($sql) as $number => $batch) {
            try {
                $b->execute($batch->sql);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    sprintf('%s, batch %d (line %d): %s', $file, $number + 1, $batch->startLine, $e->getMessage()),
                    0,
                    $e,
                );
            }
        }
    }

    private function relative(string $path): string
    {
        $root = $this->aliases->get('@root') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}

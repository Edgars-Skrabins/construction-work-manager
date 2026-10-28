<?php

declare(strict_types=1);

namespace App\Tests\Unit\Migration;

use App\Migration\SqlBatch;
use App\Migration\SqlBatchSplitter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function PHPUnit\Framework\assertSame;

final class SqlBatchSplitterTest extends TestCase
{
    public function testSplitsOnPlainGo(): void
    {
        $batches = $this->split("CREATE TABLE A (ID INT);\nGO\nCREATE VIEW V AS SELECT ID FROM A;\nGO\n");

        assertSame(['CREATE TABLE A (ID INT);', 'CREATE VIEW V AS SELECT ID FROM A;'], $this->sql($batches));
    }

    public function testSeparatorIsCaseInsensitiveAndAllowsWhitespaceAndComment(): void
    {
        $batches = $this->split("SELECT 1;\n  go  \nSELECT 2;\nGo -- next batch\nSELECT 3;");

        assertSame(['SELECT 1;', 'SELECT 2;', 'SELECT 3;'], $this->sql($batches));
    }

    public function testHandlesWindowsLineEndings(): void
    {
        $batches = $this->split("SELECT 1;\r\nGO\r\nSELECT 2;\r\n");

        assertSame(['SELECT 1;', 'SELECT 2;'], $this->sql($batches));
    }

    public function testStripsUtf8Bom(): void
    {
        $batches = $this->split("\xEF\xBB\xBFSELECT 1;\nGO");

        assertSame(['SELECT 1;'], $this->sql($batches));
    }

    public function testDoesNotSplitOnGoInsideWordsOrStrings(): void
    {
        $sql = "INSERT INTO T (NAME) VALUES (N'GO');\nSELECT GOAL FROM T; GO\nSELECT 'go' AS X;";

        assertSame([$sql], $this->sql($this->split($sql)));
    }

    public function testSkipsEmptyBatches(): void
    {
        $batches = $this->split("GO\n\nSELECT 1;\nGO\n\n   \nGO\nGO\n");

        assertSame(['SELECT 1;'], $this->sql($batches));
    }

    public function testRecordsStartLineOfEachBatch(): void
    {
        $batches = $this->split("\nSELECT 1;\nGO\n\n\nSELECT 2;\nSELECT 3;\nGO\nSELECT 4;");

        assertSame([2, 6, 9], array_map(static fn(SqlBatch $batch): int => $batch->startLine, $batches));
    }

    #[DataProvider('sqlcmdOnlySyntax')]
    public function testRejectsSqlcmdOnlySyntax(string $sql): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Line 2: sqlcmd-only syntax');

        $this->split($sql);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sqlcmdOnlySyntax(): iterable
    {
        yield 'repeated batch' => ["SELECT 1;\nGO 5"];
        yield 'setvar' => ["SELECT 1;\n:setvar NAME value"];
        yield 'include' => ["SELECT 1;\n:r other.sql"];
    }

    /**
     * @return list<SqlBatch>
     */
    private function split(string $sql): array
    {
        return (new SqlBatchSplitter())->split($sql);
    }

    /**
     * @param list<SqlBatch> $batches
     * @return list<string>
     */
    private function sql(array $batches): array
    {
        return array_map(static fn(SqlBatch $batch): string => $batch->sql, $batches);
    }
}

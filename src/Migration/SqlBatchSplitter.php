<?php

declare(strict_types=1);

namespace App\Migration;

use InvalidArgumentException;

use function implode;
use function preg_match;
use function preg_split;
use function sprintf;
use function str_starts_with;
use function substr;
use function trim;

final class SqlBatchSplitter
{
    private const string BOM = "\xEF\xBB\xBF";
    private const string SEPARATOR = '/^\s*GO\s*(?:--.*)?$/i';
    private const string REPEATED_SEPARATOR = '/^\s*GO\s+\d+/i';
    private const string SQLCMD_COMMAND = '/^\s*:\w+/';

    /**
     * @return list<SqlBatch>
     */
    public function split(string $sql): array
    {
        if (str_starts_with($sql, self::BOM)) {
            $sql = substr($sql, 3);
        }

        $lines = preg_split('/\n/', $sql);
        if ($lines === false) {
            return [];
        }

        $batches = [];
        $current = [];
        $startLine = 1;

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;

            if (preg_match(self::REPEATED_SEPARATOR, $line) === 1 || preg_match(self::SQLCMD_COMMAND, $line) === 1) {
                throw new InvalidArgumentException(sprintf(
                    'Line %d: sqlcmd-only syntax (GO n, :setvar, :r) is not supported.',
                    $lineNumber,
                ));
            }

            if (preg_match(self::SEPARATOR, $line) === 1) {
                $this->addBatch($batches, $current, $startLine);
                $current = [];
                $startLine = $lineNumber + 1;
                continue;
            }

            $current[] = $line;
        }

        $this->addBatch($batches, $current, $startLine);

        return $batches;
    }

    /**
     * @param list<SqlBatch> $batches
     * @param list<string> $lines
     */
    private function addBatch(array &$batches, array $lines, int $startLine): void
    {
        foreach ($lines as $offset => $line) {
            if (trim($line) !== '') {
                $batches[] = new SqlBatch(trim(implode("\n", $lines)), $startLine + $offset);
                return;
            }
        }
    }
}

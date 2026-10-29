<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\InUseException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Exception\IntegrityException;

use function PHPUnit\Framework\assertSame;

final class InUseExceptionTest extends TestCase
{
    public function testConstraintConflictBecomesInUse(): void
    {
        $integrity = new IntegrityException('conflict', ['23000', 547, 'conflict']);

        $inUse = InUseException::fromDelete($integrity, 'Still in use.');

        assertSame('Still in use.', $inUse->getMessage());
        assertSame($integrity, $inUse->getPrevious());
    }

    public function testOtherIntegrityErrorIsRethrown(): void
    {
        $integrity = new IntegrityException('duplicate', ['23000', 2627, 'duplicate']);

        try {
            InUseException::fromDelete($integrity, 'Still in use.');
            self::fail('Expected the integrity error to be rethrown.');
        } catch (IntegrityException $e) {
            assertSame($integrity, $e);
        }
    }
}

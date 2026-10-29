<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\InUseException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Exception\IntegrityException;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class InUseExceptionTest extends TestCase
{
    public function testGuardRunsASuccessfulDelete(): void
    {
        $deleted = false;

        InUseException::guard(static function () use (&$deleted): void {
            $deleted = true;
        }, 'Still in use.');

        assertTrue($deleted);
    }

    public function testGuardTurnsAConstraintConflictIntoInUse(): void
    {
        $conflict = new IntegrityException('conflict', ['23000', 547, 'conflict']);

        try {
            InUseException::guard(static fn() => throw $conflict, 'Still in use.');
            self::fail('Expected an InUseException.');
        } catch (InUseException $e) {
            assertSame('Still in use.', $e->getMessage());
            assertSame($conflict, $e->getPrevious());
        }
    }

    public function testGuardRethrowsOtherIntegrityErrors(): void
    {
        $duplicate = new IntegrityException('duplicate', ['23000', 2627, 'duplicate']);

        try {
            InUseException::guard(static fn() => throw $duplicate, 'Still in use.');
            self::fail('Expected the integrity error to be rethrown.');
        } catch (IntegrityException $e) {
            assertSame($duplicate, $e);
        }
    }

    public function testOnlyErrorNumber547IsAConstraintConflict(): void
    {
        assertTrue(InUseException::isConstraintConflict(new IntegrityException('conflict', ['23000', 547, 'conflict'])));
        assertFalse(InUseException::isConstraintConflict(new IntegrityException('duplicate', ['23000', 2627, 'duplicate'])));
        assertFalse(InUseException::isConstraintConflict(new IntegrityException('unknown', null)));
    }
}

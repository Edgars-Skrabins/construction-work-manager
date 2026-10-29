<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\InUseException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Exception\IntegrityException;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

final class InUseExceptionTest extends TestCase
{
    public function testErrorNumber547IsAConstraintConflict(): void
    {
        assertTrue(InUseException::isConstraintConflict(new IntegrityException('conflict', ['23000', 547, 'conflict'])));
    }

    public function testOtherIntegrityErrorIsNotAConstraintConflict(): void
    {
        assertFalse(InUseException::isConstraintConflict(new IntegrityException('duplicate', ['23000', 2627, 'duplicate'])));
        assertFalse(InUseException::isConstraintConflict(new IntegrityException('unknown', null)));
    }
}

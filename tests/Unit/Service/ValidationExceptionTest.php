<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ValidationException;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class ValidationExceptionTest extends TestCase
{
    public function testMergeIntoAddsEachMessageUnderItsField(): void
    {
        $exception = new ValidationException(['name' => 'Taken.', 'role' => 'Refused.']);

        assertSame(
            ['name' => ['Too long.', 'Taken.'], 'role' => ['Refused.']],
            $exception->mergeInto(['name' => ['Too long.']]),
        );
    }

    public function testForFieldHoldsOneError(): void
    {
        assertSame(['email' => 'Used.'], ValidationException::forField('email', 'Used.')->errors);
    }
}

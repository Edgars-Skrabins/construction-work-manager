<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ValidationException;
use Closure;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Exception\IntegrityException;

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

    public function testGuardUniqueReturnsTheResultOfASuccessfulWrite(): void
    {
        assertSame(5, ValidationException::guardUnique(static fn(): int => 5, 'UQ_ACCOUNT_EMAIL', 'email', 'Used.'));
    }

    public function testGuardUniqueTurnsTheNamedUniqueViolationIntoAFieldError(): void
    {
        $violation = new IntegrityException('Violation of UNIQUE KEY constraint \'UQ_ACCOUNT_EMAIL\'.', ['23000', 2627, '']);

        try {
            ValidationException::guardUnique(self::failingWrite($violation), 'UQ_ACCOUNT_EMAIL', 'email', 'Used.');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            assertSame(['email' => 'Used.'], $e->errors);
        }
    }

    public function testGuardUniqueRethrowsOtherIntegrityErrors(): void
    {
        $otherConstraint = new IntegrityException('Violation of PRIMARY KEY constraint \'PK_ACCOUNT\'.', ['23000', 2627, '']);
        $foreignKey = new IntegrityException('The INSERT statement conflicted with UQ_ACCOUNT_EMAIL.', ['23000', 547, '']);

        foreach ([$otherConstraint, $foreignKey] as $error) {
            try {
                ValidationException::guardUnique(self::failingWrite($error), 'UQ_ACCOUNT_EMAIL', 'email', 'Used.');
                self::fail('Expected the integrity error to be rethrown.');
            } catch (IntegrityException $e) {
                assertSame($error, $e);
            }
        }
    }

    public function testForFieldHoldsOneError(): void
    {
        assertSame(['email' => 'Used.'], ValidationException::forField('email', 'Used.')->errors);
    }

    /**
     * @return Closure(): int
     */
    private static function failingWrite(IntegrityException $error): Closure
    {
        return static function () use ($error): int {
            throw $error;
        };
    }
}

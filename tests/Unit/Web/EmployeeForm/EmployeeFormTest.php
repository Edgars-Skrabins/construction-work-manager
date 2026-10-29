<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\EmployeeForm;

use App\Web\EmployeeForm\EmployeeForm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class EmployeeFormTest extends TestCase
{
    #[DataProvider('roles')]
    public function testManagerIsLockedOnlyForAdminsAndManagers(string $role, bool $expected): void
    {
        $form = new EmployeeForm();
        $form->role = $role;

        assertSame($expected, $form->managerLocked());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function roles(): iterable
    {
        yield 'admin' => ['admin', true];
        yield 'manager' => ['manager', true];
        yield 'employee' => ['employee', false];
        yield 'no role chosen' => ['', false];
        yield 'unknown role' => ['owner', false];
    }
}

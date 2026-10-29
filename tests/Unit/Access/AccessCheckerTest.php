<?php

declare(strict_types=1);

namespace App\Tests\Unit\Access;

use App\Access\AccessChecker;
use App\Access\ForbiddenException;
use App\Access\Permission;
use PHPUnit\Framework\TestCase;
use Yiisoft\Rbac\ManagerInterface;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

final class AccessCheckerTest extends TestCase
{
    public function testRepeatedChecksOfAPermissionAskRbacOnce(): void
    {
        $manager = $this->createMock(ManagerInterface::class);
        $manager->expects($this->exactly(2))
            ->method('userHasPermission')
            ->willReturnCallback(static fn(string $userId, string $permission): bool => $permission === 'assignment.update');
        $checker = new AccessChecker($manager);

        for ($row = 0; $row < 20; $row++) {
            assertTrue($checker->can(2, Permission::AssignmentUpdate));
            assertFalse($checker->can(2, Permission::AssignmentDelete));
        }
    }

    public function testChecksAreCachedPerEmployee(): void
    {
        $manager = $this->createMock(ManagerInterface::class);
        $manager->expects($this->exactly(2))
            ->method('userHasPermission')
            ->willReturnCallback(static fn(string $userId): bool => $userId === '2');
        $checker = new AccessChecker($manager);

        assertTrue($checker->can(2, Permission::SiteRead));
        assertFalse($checker->can(4, Permission::SiteRead));
        assertTrue($checker->can(2, Permission::SiteRead));
        assertFalse($checker->can(4, Permission::SiteRead));
    }

    public function testEnsureSharesTheCacheAndStillRefuses(): void
    {
        $manager = $this->createMock(ManagerInterface::class);
        $manager->expects($this->once())
            ->method('userHasPermission')
            ->willReturn(false);
        $checker = new AccessChecker($manager);

        assertFalse($checker->can(4, Permission::AssignmentCreate));

        $this->expectException(ForbiddenException::class);
        $checker->ensure(4, Permission::AssignmentCreate);
    }
}

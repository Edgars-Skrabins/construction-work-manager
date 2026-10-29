<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Shared\Access;

use App\Access\AccessChecker;
use App\Access\Role;
use App\Entity\UserIdentity;
use App\Web\Shared\Access\ViewAccess;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Yiisoft\Auth\IdentityRepositoryInterface;
use Yiisoft\Rbac\ManagerInterface;
use Yiisoft\User\CurrentUser;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

final class ViewAccessTest extends TestCase
{
    public function testRepeatedChecksOfAPermissionAskRbacOnce(): void
    {
        $manager = $this->createMock(ManagerInterface::class);
        $manager->expects($this->exactly(2))
            ->method('userHasPermission')
            ->willReturnCallback(static fn(string $userId, string $permission): bool => $permission === 'assignment.update');
        $access = new ViewAccess($this->currentUser(2), new AccessChecker($manager));

        for ($row = 0; $row < 20; $row++) {
            assertTrue($access->can('assignment.update'));
            assertFalse($access->can('assignment.delete'));
        }
    }

    public function testChecksAreCachedPerUser(): void
    {
        $manager = $this->createMock(ManagerInterface::class);
        $manager->expects($this->exactly(2))
            ->method('userHasPermission')
            ->willReturnCallback(static fn(string $userId): bool => $userId === '2');
        $currentUser = $this->currentUser(2);
        $access = new ViewAccess($currentUser, new AccessChecker($manager));

        assertTrue($access->can('site.read'));
        $currentUser->overrideIdentity(new UserIdentity(4, 'Mārtiņš', 'Liepiņš', Role::Employee));
        assertFalse($access->can('site.read'));
    }

    public function testGuestsCanDoNothingWithoutAskingRbac(): void
    {
        $manager = $this->createMock(ManagerInterface::class);
        $manager->expects($this->never())->method('userHasPermission');
        $access = new ViewAccess($this->currentUser(null), new AccessChecker($manager));

        assertFalse($access->can('site.read'));
    }

    private function currentUser(?int $id): CurrentUser
    {
        $identities = $this->createStub(IdentityRepositoryInterface::class);
        $currentUser = new CurrentUser($identities, $this->createStub(EventDispatcherInterface::class));
        if ($id !== null) {
            $currentUser->overrideIdentity(new UserIdentity($id, 'Ilze', 'Kalniņa', Role::Manager));
        }

        return $currentUser;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web\Shared\Access;

use App\Access\AccessChecker;
use App\Access\ForbiddenException;
use App\Access\Permission;
use App\Tests\CallableRequestHandler;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Integration\Web\WebClient;
use App\Web\Shared\Access\RequirePermission;
use App\Web\Shared\Access\ViewAccess;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ValueError;
use Yiisoft\Auth\IdentityRepositoryInterface;
use Yiisoft\User\CurrentUser;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class RequirePermissionTest extends IntegrationTestCase
{
    use WebClient;

    public function testGuestOnAProtectedRouteIsRedirectedToLoginBeforeThePermissionCheck(): void
    {
        $response = $this->request('GET', '/work-items');

        assertSame(302, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testUserWithoutThePermissionGetsTheForbiddenPage(): void
    {
        $this->logIn('manager@mail.test', '123123123');

        $response = $this->request('GET', '/work-items');

        assertSame(403, $response->getStatusCode());
    }

    public function testPermittedUserPassesThrough(): void
    {
        $response = $this->middleware(Permission::SiteUpdate, 1)->process($this->serverRequest(), $this->handler());

        assertSame(204, $response->getStatusCode());
    }

    public function testUserWithoutThePermissionIsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->middleware(Permission::SiteUpdate, 2)->process($this->serverRequest(), $this->handler());
    }

    public function testGuestIsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->middleware(Permission::SiteRead, null)->process($this->serverRequest(), $this->handler());
    }

    public function testForBuildsARouteMiddlewareDefinition(): void
    {
        assertSame(
            ['class' => RequirePermission::class, '__construct()' => ['permission' => Permission::WorkItemDelete]],
            RequirePermission::for(Permission::WorkItemDelete),
        );
    }

    public function testViewAccessChecksTheCurrentUser(): void
    {
        assertTrue($this->viewAccess(1)->can('employee.create'));
        assertFalse($this->viewAccess(2)->can('employee.create'));
        assertTrue($this->viewAccess(2)->can('assignment.create'));
        assertFalse($this->viewAccess(null)->can('site.read'));
    }

    public function testViewAccessRejectsAnUnknownPermission(): void
    {
        $this->expectException(ValueError::class);

        $this->viewAccess(1)->can('site.manage');
    }

    private function middleware(Permission $permission, ?int $employeeId): RequirePermission
    {
        return new RequirePermission($permission, $this->currentUser($employeeId), $this->service(AccessChecker::class));
    }

    private function viewAccess(?int $employeeId): ViewAccess
    {
        return new ViewAccess($this->currentUser($employeeId), $this->service(AccessChecker::class));
    }

    private function currentUser(?int $employeeId): CurrentUser
    {
        $identities = $this->service(IdentityRepositoryInterface::class);
        $currentUser = new CurrentUser($identities, $this->service(EventDispatcherInterface::class));

        if ($employeeId !== null) {
            $identity = $identities->findIdentity((string) $employeeId);
            self::assertNotNull($identity);
            $currentUser->overrideIdentity($identity);
        }

        return $currentUser;
    }

    private function serverRequest(): ServerRequestInterface
    {
        return $this->createRequest('GET', '/');
    }

    private function handler(): RequestHandlerInterface
    {
        $response = $this->service(ResponseFactoryInterface::class)->createResponse(204);

        return new CallableRequestHandler(static fn(): ResponseInterface => $response);
    }
}

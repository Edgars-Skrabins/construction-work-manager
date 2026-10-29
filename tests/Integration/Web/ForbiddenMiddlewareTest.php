<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Access\ForbiddenException;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\CallableRequestHandler;
use App\Web\Forbidden\ForbiddenMiddleware;
use App\Web\Shared\Rendering\RenderBeforeSessionClose;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Csrf\CsrfTokenMiddleware;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Session\SessionMiddleware;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

final class ForbiddenMiddlewareTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        $session = $this->service(SessionInterface::class);
        if ($session->isActive()) {
            $session->close();
        }
    }

    public function testForbiddenExceptionRendersTheForbiddenPage(): void
    {
        $response = $this->forbiddenMiddleware()->process($this->request(), new CallableRequestHandler(self::deny(...)));
        $body = (string) $response->getBody();

        assertSame(403, $response->getStatusCode());
        assertStringContainsString('<title>403</title>', $body);
        assertStringContainsString('You don&#039;t have permission to do this.', $body);
        assertStringContainsString('class="navbar-brand"', $body);
    }

    public function testAllowedResponsePassesThroughUnchanged(): void
    {
        $allowed = $this->service(ResponseFactoryInterface::class)->createResponse(204);

        $response = $this->forbiddenMiddleware()->process($this->request(), new CallableRequestHandler(static fn(): ResponseInterface => $allowed));

        assertSame($allowed, $response);
    }

    public function testSessionIsCommittedWhenAccessIsForbidden(): void
    {
        $session = new RecordingSession();
        $request = $this->request();
        $forbidden = $this->forbiddenMiddleware();

        $response = (new SessionMiddleware($session))->process($request, new CallableRequestHandler(
            static fn(): ResponseInterface => $forbidden->process($request, new CallableRequestHandler(self::deny(...))),
        ));

        assertSame(403, $response->getStatusCode());
        assertTrue($session->closed);
        assertFalse($session->discarded);
    }

    public function testRunsInsideTheSessionAndCsrfMiddlewareRightBeforeTheRouter(): void
    {
        $middlewares = $this->webMiddlewares();
        $position = array_search(ForbiddenMiddleware::class, $middlewares, true);

        assertSame(array_search(Router::class, $middlewares, true), $position + 1);
        assertGreaterThan(array_search(SessionMiddleware::class, $middlewares, true), $position);
        assertGreaterThan(array_search(CsrfTokenMiddleware::class, $middlewares, true), $position);
    }

    public function testPagesRenderRightBeforeTheForbiddenMiddlewareWhileTheSessionIsOpen(): void
    {
        $middlewares = $this->webMiddlewares();
        $position = array_search(RenderBeforeSessionClose::class, $middlewares, true);

        assertSame(array_search(ForbiddenMiddleware::class, $middlewares, true), $position + 1);
        assertGreaterThan(array_search(SessionMiddleware::class, $middlewares, true), $position);
        assertGreaterThan(array_search(CsrfTokenMiddleware::class, $middlewares, true), $position);
    }

    private static function deny(): ResponseInterface
    {
        throw new ForbiddenException('Denied.');
    }

    private function forbiddenMiddleware(): ForbiddenMiddleware
    {
        return $this->service(ForbiddenMiddleware::class);
    }

    private function request(): ServerRequestInterface
    {
        return $this->createRequest('GET', '/');
    }
}

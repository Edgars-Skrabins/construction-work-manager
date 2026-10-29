<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Access\ForbiddenException;
use App\Tests\Integration\IntegrationTestCase;
use App\Web\Forbidden\ForbiddenMiddleware;
use App\Web\Shared\Rendering\RenderBeforeSessionClose;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionProperty;
use Yiisoft\Csrf\CsrfTokenMiddleware;
use Yiisoft\Definitions\ArrayDefinition;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Session\SessionMiddleware;
use Yiisoft\Yii\Http\Application;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertIsArray;
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
        $response = $this->forbiddenMiddleware()->process($this->request(), self::handler(self::deny(...)));
        $body = (string) $response->getBody();

        assertSame(403, $response->getStatusCode());
        assertStringContainsString('<title>403</title>', $body);
        assertStringContainsString("You don't have permission to do this.", $body);
        assertStringContainsString('class="navbar-brand"', $body);
    }

    public function testAllowedResponsePassesThroughUnchanged(): void
    {
        $allowed = $this->service(ResponseFactoryInterface::class)->createResponse(204);

        $response = $this->forbiddenMiddleware()->process($this->request(), self::handler(static fn(): ResponseInterface => $allowed));

        assertSame($allowed, $response);
    }

    public function testSessionIsCommittedWhenAccessIsForbidden(): void
    {
        $session = new RecordingSession();
        $request = $this->request();
        $forbidden = $this->forbiddenMiddleware();

        $response = (new SessionMiddleware($session))->process($request, self::handler(
            static fn(): ResponseInterface => $forbidden->process($request, self::handler(self::deny(...))),
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

    /**
     * @param callable(): ResponseInterface $handle
     */
    private static function handler(callable $handle): RequestHandlerInterface
    {
        return new readonly class ($handle) implements RequestHandlerInterface {
            /**
             * @param callable(): ResponseInterface $handle
             */
            public function __construct(private mixed $handle) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handle)();
            }
        };
    }

    private function forbiddenMiddleware(): ForbiddenMiddleware
    {
        return $this->service(ForbiddenMiddleware::class);
    }

    private function request(): ServerRequestInterface
    {
        return $this->service(ServerRequestFactoryInterface::class)->createServerRequest('GET', 'http://localhost/');
    }

    /**
     * @return list<mixed>
     */
    private function webMiddlewares(): array
    {
        $params = [];
        /** @var array<string, array{'__construct()': array{dispatcher: DynamicReference}}> $definitions */
        $definitions = require dirname(__DIR__, 3) . '/config/web/di/application.php';
        $dispatcher = $definitions[Application::class]['__construct()']['dispatcher'];
        $definition = (new ReflectionProperty($dispatcher, 'definition'))->getValue($dispatcher);
        assertInstanceOf(ArrayDefinition::class, $definition);
        $arguments = $definition->getMethodsAndProperties()['withMiddlewares()'][2] ?? null;
        assertIsArray($arguments);
        assertIsArray($arguments[0]);

        return array_values($arguments[0]);
    }
}

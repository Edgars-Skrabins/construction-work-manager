<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Environment;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

final class WebSmokeTest extends TestCase
{
    public function testHomePageIsRendered(): void
    {
        $response = $this->request('/');
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('<title>Construction Work Manager</title>', $body);
        assertStringContainsString('class="navbar-brand"', $body);
    }

    public function testUnknownPageReturnsNotFoundWithLayout(): void
    {
        $response = $this->request('/missing-page');
        $body = (string) $response->getBody();

        assertSame(404, $response->getStatusCode());
        assertStringContainsString('<title>404</title>', $body);
        assertStringContainsString('<strong>/missing-page</strong>', $body);
        assertStringContainsString('class="navbar-brand"', $body);
    }

    private function request(string $path): ResponseInterface
    {
        $container = (new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        ))->getContainer();

        $application = $container->get(Application::class);
        $requestFactory = $container->get(ServerRequestFactoryInterface::class);
        self::assertInstanceOf(Application::class, $application);
        self::assertInstanceOf(ServerRequestFactoryInterface::class, $requestFactory);

        return $application->handle($requestFactory->createServerRequest('GET', 'http://localhost' . $path));
    }
}

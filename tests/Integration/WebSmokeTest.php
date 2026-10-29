<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Yiisoft\Yii\Http\Application;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

final class WebSmokeTest extends IntegrationTestCase
{
    public function testLoginPageIsRenderedWithLayout(): void
    {
        $response = $this->request('/login');
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('<title>Log in</title>', $body);
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
        return $this->service(Application::class)->handle(
            $this->service(ServerRequestFactoryInterface::class)->createServerRequest('GET', 'http://localhost' . $path),
        );
    }
}

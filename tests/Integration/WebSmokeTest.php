<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

final class WebSmokeTest extends IntegrationTestCase
{
    public function testLoginPageIsRenderedWithLayout(): void
    {
        $response = $this->handleRequest('GET', '/login');
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('<title>Log in</title>', $body);
        assertStringContainsString('class="navbar-brand"', $body);
    }

    public function testUnknownPageReturnsNotFoundWithLayout(): void
    {
        $response = $this->handleRequest('GET', '/missing-page');
        $body = (string) $response->getBody();

        assertSame(404, $response->getStatusCode());
        assertStringContainsString('<title>404</title>', $body);
        assertStringContainsString('<strong>/missing-page</strong>', $body);
        assertStringContainsString('class="navbar-brand"', $body);
    }
}

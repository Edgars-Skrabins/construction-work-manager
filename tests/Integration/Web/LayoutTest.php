<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertDoesNotMatchRegularExpression;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class LayoutTest extends IntegrationTestCase
{
    use WebClient;

    private const string TOGGLER = '<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main-navbar" aria-controls="main-navbar" aria-expanded="false" aria-label="Toggle navigation">';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function landingPages(): iterable
    {
        yield 'admin' => ['admin@mail.test', '/employees'];
        yield 'manager' => ['manager@mail.test', '/assignments'];
        yield 'employee' => ['employee@mail.test', '/my-assignments'];
    }

    #[DataProvider('landingPages')]
    public function testHomeRedirectsToTheRolesLandingPage(string $email, string $landingPage): void
    {
        $this->logIn($email, '123123123');

        $response = $this->request('GET', '/');

        assertSame(302, $response->getStatusCode());
        assertSame($landingPage, $response->getHeaderLine('Location'));
    }

    #[DataProvider('landingPages')]
    public function testNavbarCollapsesBehindATogglerWithTheLinksAndLogoutInside(string $email, string $landingPage): void
    {
        $this->logIn($email, '123123123');

        $response = $this->request('GET', $landingPage);
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('<nav class="navbar navbar-expand-lg navbar-dark bg-dark">', $body);
        assertStringContainsString(self::TOGGLER, $body);
        assertMatchesRegularExpression('#<div class="collapse navbar-collapse" id="main-navbar">\s*<ul class="navbar-nav me-auto mb-2 mb-lg-0">.*href="' . preg_quote($landingPage, '#') . '".*</ul>\s*<form [^>]*action="/logout">.*Log out</button>\s*</form>\s*</div>#s', $body);
        assertMatchesRegularExpression('#<script src="/assets/bootstrap/[^"]*bootstrap\.bundle\.min\.js[^"]*"#', $body);
    }

    public function testGuestGetsNoToggler(): void
    {
        $body = (string) $this->request('GET', '/login')->getBody();

        assertStringContainsString('class="navbar-brand"', $body);
        assertStringNotContainsString('navbar-toggler', $body);
        assertStringNotContainsString('id="main-navbar"', $body);
    }

    #[DataProvider('landingPages')]
    public function testLandingPagesHaveNoInlineJavaScript(string $email, string $landingPage): void
    {
        $this->logIn($email, '123123123');

        $body = (string) $this->request('GET', $landingPage)->getBody();

        assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#', $body);
        assertDoesNotMatchRegularExpression('#\son[a-z]+\s*=#i', $body);
    }

    public function testLoginPageHasNoInlineJavaScript(): void
    {
        $body = (string) $this->request('GET', '/login')->getBody();

        assertDoesNotMatchRegularExpression('#<script(?![^>]*\ssrc=)[^>]*>#', $body);
        assertDoesNotMatchRegularExpression('#\son[a-z]+\s*=#i', $body);
    }
}

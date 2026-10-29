<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

final class LazyRenderingSessionTest extends IntegrationTestCase
{
    use WebClient;

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function pages(): iterable
    {
        yield 'login page as guest' => ['/login', false];
        yield 'unknown page as guest' => ['/no-such-page', false];
        yield 'unknown page as admin' => ['/no-such-page', true];
        yield 'protected page as admin' => ['/work-items', true];
    }

    #[DataProvider('pages')]
    public function testRenderingThePageLeavesTheSessionClosed(string $path, bool $loggedIn): void
    {
        if ($loggedIn) {
            $this->logIn('admin@mail.test', '123123123');
        }

        $response = $this->request('GET', $path);
        (string) $response->getBody();

        assertNotSame(PHP_SESSION_ACTIVE, session_status());
        assertLessThanOrEqual(1, count($response->getHeader('Set-Cookie')));
    }

    #[DataProvider('pages')]
    public function testLoginFormStillValidatesAfterThePage(string $path, bool $loggedIn): void
    {
        if ($loggedIn) {
            $this->logIn('admin@mail.test', '123123123');
        }
        (string) $this->request('GET', $path)->getBody();
        $this->cookies = [];

        $response = $this->logIn('admin@mail.test', 'wrong-password');

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('Invalid email or password.', (string) $response->getBody());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;
use Yiisoft\Session\SessionInterface;

use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class AuthenticationTest extends IntegrationTestCase
{
    use WebClient;

    public function testGuestIsRedirectedToLogin(): void
    {
        $response = $this->request('GET', '/');

        assertSame(302, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testLoginPageRendersTheFormWithCsrfToken(): void
    {
        $body = (string) $this->request('GET', '/login')->getBody();

        assertStringContainsString('<title>Log in</title>', $body);
        assertMatchesRegularExpression('/<input type="hidden" name="_csrf" value="[^"]+">/', $body);
        assertStringNotContainsString('Log out', $body);
    }

    public function testWrongPasswordShowsTheGenericError(): void
    {
        $response = $this->logIn('admin@mail.test', 'wrong-password');

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('Invalid email or password.', (string) $response->getBody());
        assertStringContainsString('value="admin@mail.test"', (string) $response->getBody());
    }

    public function testEmptyFieldsAskForEmailAndPassword(): void
    {
        $response = $this->logIn('admin@mail.test', '');

        assertStringContainsString('Enter your email and password.', (string) $response->getBody());
    }

    public function testLoginReturnsToTheRequestedPageAndShowsTheUser(): void
    {
        $this->request('GET', '/');

        $response = $this->logIn(' Admin@Mail.test ', '123123123');

        assertSame(302, $response->getStatusCode());
        assertSame('/', $response->getHeaderLine('Location'));

        assertSame('/employees', $this->request('GET', '/')->getHeaderLine('Location'));
        $page = $this->request('GET', '/employees');
        $body = (string) $page->getBody();
        assertSame(200, $page->getStatusCode());
        assertStringContainsString('<title>Employees</title>', $body);
        assertStringContainsString('Jānis Bērziņš (admin)', $body);
        assertStringContainsString('Log out', $body);
    }

    public function testEmailIsMatchedIgnoringCaseAndSurroundingSpaces(): void
    {
        $response = $this->logIn('  Admin@Mail.TEST  ', '123123123');

        assertSame(302, $response->getStatusCode());
        assertStringContainsString('Jānis Bērziņš (admin)', (string) $this->request('GET', '/employees')->getBody());
    }

    public function testAccountDeactivatedWhileLoggedInIsLoggedOutOnTheNextRequest(): void
    {
        $this->logIn('employee@mail.test', '123123123');
        assertSame(200, $this->request('GET', '/my-assignments')->getStatusCode());

        $this->setActive(4, false);
        try {
            $response = $this->request('GET', '/');
        } finally {
            $this->setActive(4, true);
        }

        assertSame(302, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testLoginPageRedirectsLoggedInUserHome(): void
    {
        $this->logIn('employee@mail.test', '123123123');

        $response = $this->request('GET', '/login');

        assertSame(302, $response->getStatusCode());
        assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->logIn('admin@mail.test', '123123123');
        $token = $this->csrfToken((string) $this->request('GET', '/employees')->getBody());

        $response = $this->request('POST', '/logout', ['_csrf' => $token]);

        assertSame(302, $response->getStatusCode());
        assertSame('/login', $response->getHeaderLine('Location'));
        assertSame(302, $this->request('GET', '/')->getStatusCode());
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $response = $this->request('POST', '/login', ['email' => 'admin@mail.test', 'password' => '123123123']);

        assertSame(422, $response->getStatusCode());
    }

    public function testSessionOutlivesTheAuthTimeout(): void
    {
        $session = $this->service(SessionInterface::class);
        $session->open();
        $lifetime = (int) ini_get('session.gc_maxlifetime');
        $session->destroy();

        assertGreaterThanOrEqual(28800, $lifetime);
    }

    private function setActive(int $employeeId, bool $active): void
    {
        $this->db()->createCommand()->update('ACCOUNT', ['IS_ACTIVE' => $active ? 1 : 0], ['EMPLOYEE_ID' => $employeeId])->execute();
    }
}

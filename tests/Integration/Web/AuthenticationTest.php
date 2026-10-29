<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Session\SessionInterface;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class AuthenticationTest extends IntegrationTestCase
{
    use WebClient;

    private const string THROTTLED = 'Too many attempts. Try again in 15 minutes.';

    protected function setUp(): void
    {
        $this->clearThrottle();
    }

    protected function tearDown(): void
    {
        $this->clearThrottle();
    }

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

    public function testFifthWrongPasswordLocksTheEmailEvenForTheRightPassword(): void
    {
        for ($i = 0; $i < 5; $i++) {
            assertStringContainsString('Invalid email or password.', (string) $this->logIn('admin@mail.test', 'wrong-password')->getBody());
        }

        $response = $this->logIn(' Admin@Mail.test ', '123123123');
        $body = (string) $response->getBody();

        assertSame(429, $response->getStatusCode());
        $this->assertRetryAfterIsTheWindow($response);
        assertStringContainsString(self::THROTTLED, $body);
        assertStringNotContainsString('Invalid email or password.', $body);
        assertStringContainsString('value=" Admin@Mail.test "', $body);
        assertSame(302, $this->request('GET', '/')->getStatusCode());
    }

    public function testUnknownEmailIsThrottledExactlyLikeAKnownOne(): void
    {
        $responses = [];
        foreach (['admin@mail.test', 'nobody@mail.test'] as $email) {
            for ($i = 0; $i < 5; $i++) {
                $this->logIn($email, 'wrong-password');
            }
            $response = $this->logIn($email, 'wrong-password');
            $responses[] = [$response->getStatusCode(), preg_replace('/"[\w-]{40,}={0,2}"/', '""', str_replace($email, '', (string) $response->getBody()))];
            $this->clearThrottle();
        }

        assertSame(429, $responses[0][0]);
        assertStringContainsString(self::THROTTLED, (string) $responses[0][1]);
        assertSame($responses[0], $responses[1]);
    }

    public function testSuccessfulLoginClearsTheEmailFailures(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->logIn('admin@mail.test', 'wrong-password');
        }
        assertSame(302, $this->logIn('admin@mail.test', '123123123')->getStatusCode());
        $this->cookies = [];

        for ($i = 0; $i < 4; $i++) {
            $this->logIn('admin@mail.test', 'wrong-password');
        }

        assertSame('/', $this->logIn('admin@mail.test', '123123123')->getHeaderLine('Location'));
    }

    public function testManyFailuresFromOneAddressBlockEveryEmail(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->logIn(sprintf('spray%d@mail.test', intdiv($i, 4)), 'wrong-password');
        }

        $response = $this->logIn('employee@mail.test', '123123123');

        assertSame(429, $response->getStatusCode());
        assertStringContainsString(self::THROTTLED, (string) $response->getBody());
    }

    private function assertRetryAfterIsTheWindow(ResponseInterface $response): void
    {
        $retryAfter = (int) $response->getHeaderLine('Retry-After');
        assertGreaterThan(840, $retryAfter);
        assertLessThanOrEqual(900, $retryAfter);
    }

    private function clearThrottle(): void
    {
        $this->db()->createCommand('DELETE FROM AUTH_THROTTLE')->execute();
    }

    private function setActive(int $employeeId, bool $active): void
    {
        $this->db()->createCommand()->update('ACCOUNT', ['IS_ACTIVE' => $active ? 1 : 0], ['EMPLOYEE_ID' => $employeeId])->execute();
    }
}

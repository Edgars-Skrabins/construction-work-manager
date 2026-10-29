<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Tests\Integration\IntegrationTestCase;

use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class SetPasswordTest extends IntegrationTestCase
{
    use WebClient;

    private const string EMAIL = 'set.password@mail.test';

    protected function tearDown(): void
    {
        $this->resetServices();
        $this->db()->createCommand('DELETE FROM ACCOUNT WHERE EMAIL = :email', [':email' => self::EMAIL])->execute();
        $this->db()->createCommand("DELETE FROM EMPLOYEE WHERE FIRST_NAME = N'Iveta' AND LAST_NAME = N'Paroļa'")->execute();
    }

    public function testAdminCreatedEmployeeSetsAPasswordWithTheCodeAndCanLogInLater(): void
    {
        $code = $this->createEmployeeAsAdmin();
        $this->cookies = [];

        assertStringContainsString('First login? Set your password', (string) $this->request('GET', '/login')->getBody());
        $response = $this->submit('/set-password', ['email' => self::EMAIL, 'setupCode' => $code, 'password' => 'new password', 'passwordRepeat' => 'new password']);

        assertSame(302, $response->getStatusCode());
        assertSame('/', $response->getHeaderLine('Location'));
        assertStringContainsString('Iveta Paroļa (employee)', (string) $this->request('GET', '/')->getBody());

        $this->cookies = [];
        $this->logIn(self::EMAIL, 'new password');
        assertStringContainsString('Iveta Paroļa (employee)', (string) $this->request('GET', '/')->getBody());
    }

    public function testWrongCodeShowsTheGenericErrorAndKeepsOnlyTheEmail(): void
    {
        $this->createEmployeeAsAdmin();
        $this->cookies = [];

        $response = $this->submit('/set-password', ['email' => self::EMAIL, 'setupCode' => 'AAAAA-AAAAA', 'password' => 'new password', 'passwordRepeat' => 'new password']);
        $body = (string) $response->getBody();

        assertSame(200, $response->getStatusCode());
        assertStringContainsString('Invalid email or setup code.', $body);
        assertStringContainsString('value="' . self::EMAIL . '"', $body);
        assertStringNotContainsString('AAAAA-AAAAA', $body);
    }

    public function testPasswordRulesAreShownUnderTheFields(): void
    {
        $body = (string) $this->submit('/set-password', ['email' => self::EMAIL, 'setupCode' => 'AAAAA-AAAAA', 'password' => 'short', 'passwordRepeat' => 'other'])->getBody();

        assertStringContainsString('Use at least 8 characters.', $body);
        assertStringContainsString('The passwords don&#039;t match.', $body);
    }

    public function testLoggedInUserIsRedirectedHome(): void
    {
        $this->logIn('employee@mail.test', '123123123');

        $response = $this->request('GET', '/set-password');

        assertSame(302, $response->getStatusCode());
        assertSame('/', $response->getHeaderLine('Location'));
    }

    private function createEmployeeAsAdmin(): string
    {
        $this->logIn('admin@mail.test', '123123123');
        $response = $this->submit('/employees/create', [
            'firstName' => 'Iveta',
            'lastName' => 'Paroļa',
            'birthDate' => '1991-03-03',
            'accessLevelId' => '1',
            'role' => 'employee',
            'managerId' => '2',
            'email' => self::EMAIL,
            'loginActive' => '1',
        ]);
        assertSame(302, $response->getStatusCode());

        $body = (string) $this->request('GET', parse_url($response->getHeaderLine('Location'), PHP_URL_PATH) ?: '')->getBody();
        assertMatchesRegularExpression('/font-monospace">([A-Z2-9]{5}-[A-Z2-9]{5})</', $body);
        preg_match('/font-monospace">([A-Z2-9]{5}-[A-Z2-9]{5})</', $body, $matches);

        return $matches[1] ?? '';
    }
}

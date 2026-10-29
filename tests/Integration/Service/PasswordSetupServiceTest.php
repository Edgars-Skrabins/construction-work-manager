<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Access\Role;
use App\Repository\AccountRepositoryInterface;
use App\Repository\AuthThrottleRepositoryInterface;
use App\Service\AttemptThrottle;
use App\Service\EmployeeInput;
use App\Service\EmployeeService;
use App\Service\LoginService;
use App\Service\PasswordSetupService;
use App\Service\SavedEmployee;
use App\Service\SetupCodeGenerator;
use App\Service\TooManyAttemptsException;
use App\Tests\FixedClock;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class PasswordSetupServiceTest extends IntegrationTestCase
{
    private const string EMAIL = 'first.login@mail.test';
    private const string PASSWORD = 'correct horse';
    private const string IP = '203.0.113.7';

    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
        $session = $this->service(SessionInterface::class);
        if ($session->isActive()) {
            $session->close();
        }
    }

    public function testCorrectCodeSetsThePasswordClearsTheCodeAndLogsIn(): void
    {
        $saved = $this->newEmployee();

        assertTrue($this->passwordSetup()->setPassword(' First.Login@Mail.test ', strtolower((string) $saved->setupCode), self::PASSWORD, self::IP));

        $account = $this->accounts()->findByEmployeeId($saved->id);
        assertNotNull($account);
        assertNull($account->setupCodeHash);
        assertNull($account->setupCodeExpiresAt);
        assertTrue((new PasswordHasher())->validate(self::PASSWORD, (string) $account->passwordHash));
        assertSame((string) $saved->id, $this->service(CurrentUser::class)->getId());
    }

    public function testCodeCanOnlyBeUsedOnce(): void
    {
        $saved = $this->newEmployee();
        assertTrue($this->passwordSetup()->setPassword(self::EMAIL, (string) $saved->setupCode, self::PASSWORD, self::IP));

        assertFalse($this->passwordSetup()->setPassword(self::EMAIL, (string) $saved->setupCode, 'another password', self::IP));
        assertTrue((new PasswordHasher())->validate(self::PASSWORD, (string) $this->accounts()->findByEmployeeId($saved->id)?->passwordHash));
    }

    public function testWrongCodeIsRejected(): void
    {
        $this->newEmployee();

        assertFalse($this->passwordSetup()->setPassword(self::EMAIL, 'AAAAA-AAAAA', self::PASSWORD, self::IP));
    }

    public function testExpiredCodeIsRejected(): void
    {
        $saved = $this->newEmployee();
        $expiresAt = $this->accounts()->findByEmployeeId($saved->id)?->setupCodeExpiresAt;
        assertNotNull($expiresAt);

        assertFalse($this->passwordSetupAt($expiresAt)->setPassword(self::EMAIL, (string) $saved->setupCode, self::PASSWORD, self::IP));
        assertNull($this->accounts()->findByEmployeeId($saved->id)?->passwordHash);
    }

    public function testCodeReplacedByANewOneIsRejected(): void
    {
        $saved = $this->newEmployee();
        $this->service(EmployeeService::class)->newSetupCode(1, $saved->id);

        assertFalse($this->passwordSetup()->setPassword(self::EMAIL, (string) $saved->setupCode, self::PASSWORD, self::IP));
    }

    public function testDeactivatedAccountIsRejected(): void
    {
        $saved = $this->newEmployee(loginActive: false);

        assertFalse($this->passwordSetup()->setPassword(self::EMAIL, (string) $saved->setupCode, self::PASSWORD, self::IP));
        assertNull($this->accounts()->findByEmployeeId($saved->id)?->passwordHash);
    }

    public function testAccountWithAPasswordAndUnknownEmailAreRejected(): void
    {
        assertFalse($this->passwordSetup()->setPassword('employee@mail.test', 'AAAAA-AAAAA', self::PASSWORD, self::IP));
        assertFalse($this->passwordSetup()->setPassword('nobody@mail.test', 'AAAAA-AAAAA', self::PASSWORD, self::IP));
        assertFalse($this->passwordSetup()->setPassword('nobody@mail.test', '', self::PASSWORD, self::IP));
    }

    public function testFifthFailureLocksTheEmailAndSuccessClearsIt(): void
    {
        $saved = $this->newEmployee();
        for ($i = 0; $i < 4; $i++) {
            assertFalse($this->passwordSetup()->setPassword(self::EMAIL, 'AAAAA-AAAAA', self::PASSWORD, self::IP));
        }
        assertFalse($this->passwordSetup()->setPassword(' First.Login@Mail.TEST ', 'AAAAA-AAAAA', self::PASSWORD, '198.51.100.1'));

        try {
            $this->passwordSetup()->setPassword(self::EMAIL, (string) $saved->setupCode, self::PASSWORD, '198.51.100.2');
            self::fail('The sixth attempt should be throttled.');
        } catch (TooManyAttemptsException $exception) {
            assertGreaterThan(840, $exception->retryAfterSeconds);
        }
        assertNull($this->accounts()->findByEmployeeId($saved->id)?->passwordHash);

        $this->db()->createCommand('DELETE FROM AUTH_THROTTLE')->execute();
        assertFalse($this->passwordSetup()->setPassword(self::EMAIL, 'AAAAA-AAAAA', self::PASSWORD, self::IP));
        assertTrue($this->passwordSetup()->setPassword(self::EMAIL, (string) $saved->setupCode, self::PASSWORD, self::IP));
        assertNull($this->service(AuthThrottleRepositoryInterface::class)->find('setup.email', hash('sha256', self::EMAIL)));
    }

    public function testUnknownEmailIsThrottledLikeAKnownOne(): void
    {
        for ($i = 0; $i < 5; $i++) {
            assertFalse($this->passwordSetup()->setPassword('nobody@mail.test', 'AAAAA-AAAAA', self::PASSWORD, self::IP));
        }

        $this->expectException(TooManyAttemptsException::class);
        $this->passwordSetup()->setPassword('nobody@mail.test', 'AAAAA-AAAAA', self::PASSWORD, '198.51.100.3');
    }

    private function newEmployee(bool $loginActive = true): SavedEmployee
    {
        $saved = $this->service(EmployeeService::class)->create(1, new EmployeeInput(
            'Ieva',
            'Pirmā',
            new DateTimeImmutable('1995-02-02'),
            1,
            Role::Employee,
            2,
            self::EMAIL,
            $loginActive,
        ));
        assertNotNull($saved->setupCode);

        return $saved;
    }

    private function passwordSetup(): PasswordSetupService
    {
        return $this->service(PasswordSetupService::class);
    }

    private function passwordSetupAt(DateTimeImmutable $now): PasswordSetupService
    {
        return new PasswordSetupService(
            $this->service(AccountRepositoryInterface::class),
            $this->service(PasswordHasher::class),
            $this->service(SetupCodeGenerator::class),
            $this->service(LoginService::class),
            new FixedClock($now),
            $this->service(AttemptThrottle::class),
        );
    }

    private function accounts(): AccountRepositoryInterface
    {
        return $this->service(AccountRepositoryInterface::class);
    }
}

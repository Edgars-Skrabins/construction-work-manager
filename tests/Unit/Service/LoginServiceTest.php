<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Access\Role;
use App\Entity\Account;
use App\Entity\AuthThrottle;
use App\Entity\UserIdentity;
use App\Repository\AccountRepositoryInterface;
use App\Repository\AuthThrottleRepositoryInterface;
use App\Service\AttemptThrottle;
use App\Service\LoginService;
use App\Service\ThrottleLimits;
use App\Service\TooManyAttemptsException;
use App\Tests\FixedClock;
use ArrayObject;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Yiisoft\Auth\IdentityRepositoryInterface;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\Session\NullSession;
use Yiisoft\User\CurrentUser;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertIsString;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class LoginServiceTest extends TestCase
{
    private const string PASSWORD = 'correct-password';
    private const string IP = '203.0.113.7';

    /** @var ArrayObject<int, string> */
    private ArrayObject $savedHashes;

    private CurrentUser $currentUser;

    /** @var ArrayObject<string, AuthThrottle> */
    private ArrayObject $throttles;

    public function testCorrectCredentialsLogInTheEmployee(): void
    {
        assertTrue($this->service()->login('active@mail.test', self::PASSWORD, self::IP));
        assertSame('1', $this->currentUser->getId());
    }

    #[DataProvider('rejectedLogins')]
    public function testRejectedLoginStaysGuest(string $email, string $password): void
    {
        assertFalse($this->service()->login($email, $password, self::IP));
        assertTrue($this->currentUser->isGuest());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedLogins(): iterable
    {
        yield 'wrong password' => ['active@mail.test', 'wrong-password'];
        yield 'unknown email' => ['nobody@mail.test', self::PASSWORD];
        yield 'inactive account' => ['inactive@mail.test', self::PASSWORD];
        yield 'account without a password' => ['nopassword@mail.test', self::PASSWORD];
        yield 'empty password' => ['active@mail.test', ''];
    }

    public function testFifthFailureLocksTheEmailEvenForTheRightPassword(): void
    {
        $service = $this->service();
        for ($i = 0; $i < 5; $i++) {
            assertFalse($service->login('active@mail.test', 'wrong-password', self::IP));
        }

        try {
            $service->login(' Active@Mail.TEST ', self::PASSWORD, '198.51.100.2');
            self::fail('The sixth attempt should be throttled.');
        } catch (TooManyAttemptsException $exception) {
            assertSame(900, $exception->retryAfterSeconds);
        }
        assertTrue($this->currentUser->isGuest());
    }

    public function testUnknownEmailIsThrottledLikeAKnownOne(): void
    {
        $service = $this->service();
        for ($i = 0; $i < 5; $i++) {
            assertFalse($service->login('nobody@mail.test', self::PASSWORD, self::IP));
        }

        $this->expectException(TooManyAttemptsException::class);
        $service->login('nobody@mail.test', self::PASSWORD, '198.51.100.2');
    }

    public function testEmptyPasswordIsNotCounted(): void
    {
        $service = $this->service();
        for ($i = 0; $i < 5; $i++) {
            $service->login('active@mail.test', '', self::IP);
        }

        assertTrue($service->login('active@mail.test', self::PASSWORD, self::IP));
    }

    public function testSuccessClearsTheEmailButNotTheAddress(): void
    {
        $service = $this->service();
        for ($i = 0; $i < 4; $i++) {
            $service->login('active@mail.test', 'wrong-password', self::IP);
        }
        assertTrue($service->login('active@mail.test', self::PASSWORD, self::IP));

        for ($i = 0; $i < 4; $i++) {
            assertFalse($service->login('active@mail.test', 'wrong-password', self::IP));
        }
        assertSame(8, $this->throttles['login.ip|' . hash('sha256', self::IP)]?->failures);
    }

    public function testOutdatedHashIsRehashedAfterLogin(): void
    {
        assertTrue($this->service()->login('outdated@mail.test', self::PASSWORD, self::IP));

        $hash = $this->savedHashes[5];
        assertIsString($hash);
        $hasher = new PasswordHasher();
        assertTrue($hasher->validate(self::PASSWORD, $hash));
        assertFalse($hasher->needsRehash($hash));
    }

    public function testCurrentHashIsNotRewritten(): void
    {
        $this->service()->login('active@mail.test', self::PASSWORD, self::IP);

        assertFalse($this->savedHashes->offsetExists(1));
    }

    public function testAlreadyVerifiedEmployeeIsLoggedInById(): void
    {
        assertTrue($this->service()->loginEmployee(7));
        assertSame('7', $this->currentUser->getId());
    }

    public function testLogoutMakesTheUserAGuest(): void
    {
        $service = $this->service();
        $service->login('active@mail.test', self::PASSWORD, self::IP);

        $service->logout();

        assertTrue($this->currentUser->isGuest());
    }

    private function service(): LoginService
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash(self::PASSWORD);
        $accounts = [
            'active@mail.test' => new Account(1, 'active@mail.test', $hash, true),
            'inactive@mail.test' => new Account(2, 'inactive@mail.test', $hash, false),
            'nopassword@mail.test' => new Account(3, 'nopassword@mail.test', null, true),
            'outdated@mail.test' => new Account(5, 'outdated@mail.test', password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]), true),
        ];

        $this->savedHashes = new ArrayObject();
        $accountRepository = new class ($accounts, $this->savedHashes) implements AccountRepositoryInterface {
            /**
             * @param array<string, Account> $accounts
             * @param ArrayObject<int, string> $savedHashes
             */
            public function __construct(
                private readonly array $accounts,
                private readonly ArrayObject $savedHashes,
            ) {}

            public function findByEmail(string $email): ?Account
            {
                return $this->accounts[$email] ?? null;
            }

            public function updatePasswordHash(int $employeeId, string $passwordHash): void
            {
                $this->savedHashes[$employeeId] = $passwordHash;
            }

            public function findByEmployeeId(int $employeeId): ?Account
            {
                throw new LogicException('Not used by LoginService.');
            }

            public function create(int $employeeId, string $email, bool $isActive, string $setupCodeHash, DateTimeImmutable $expiresAt): void
            {
                throw new LogicException('Not used by LoginService.');
            }

            public function updateEmail(int $employeeId, string $email): void
            {
                throw new LogicException('Not used by LoginService.');
            }

            public function setActive(int $employeeId, bool $isActive): void
            {
                throw new LogicException('Not used by LoginService.');
            }

            public function replaceSetupCode(int $employeeId, string $setupCodeHash, DateTimeImmutable $expiresAt): void
            {
                throw new LogicException('Not used by LoginService.');
            }

            public function completeSetup(int $employeeId, string $setupCodeHash, string $passwordHash, DateTimeImmutable $now): bool
            {
                throw new LogicException('Not used by LoginService.');
            }

            public function delete(int $employeeId): void
            {
                throw new LogicException('Not used by LoginService.');
            }
        };

        $identityRepository = new class implements IdentityRepositoryInterface {
            public function findIdentity(string $id): UserIdentity
            {
                return new UserIdentity((int) $id, 'Test', 'User', Role::Employee);
            }
        };

        $events = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                return $event;
            }
        };

        $this->currentUser = new CurrentUser($identityRepository, $events);

        $this->throttles = new ArrayObject();
        $throttleRepository = new class ($this->throttles) implements AuthThrottleRepositoryInterface {
            /**
             * @param ArrayObject<string, AuthThrottle> $rows
             */
            public function __construct(
                private readonly ArrayObject $rows,
            ) {}

            public function find(string $scope, string $keyHash): ?AuthThrottle
            {
                return $this->rows[$scope . '|' . $keyHash] ?? null;
            }

            public function recordFailure(string $scope, string $keyHash, DateTimeImmutable $now, DateTimeImmutable $windowStartsAfter): void
            {
                $row = $this->find($scope, $keyHash);
                $this->rows[$scope . '|' . $keyHash] = $row === null || $row->windowStart <= $windowStartsAfter
                    ? new AuthThrottle(1, $now)
                    : new AuthThrottle($row->failures + 1, $row->windowStart);
            }

            public function clear(string $scope, string $keyHash): void
            {
                unset($this->rows[$scope . '|' . $keyHash]);
            }
        };
        $throttle = new AttemptThrottle(
            $throttleRepository,
            new ThrottleLimits(windowMinutes: 15, emailLimit: 5, ipLimit: 20, apiIpLimit: 20),
            new FixedClock(new DateTimeImmutable('2026-10-29 10:00:00')),
        );

        return new LoginService($accountRepository, $identityRepository, $hasher, $this->currentUser, new NullSession(), $throttle);
    }
}

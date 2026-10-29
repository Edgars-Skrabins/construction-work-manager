<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Access\Role;
use App\Entity\Account;
use App\Entity\UserIdentity;
use App\Repository\AccountRepositoryInterface;
use App\Service\LoginService;
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

    /** @var ArrayObject<int, string> */
    private ArrayObject $savedHashes;

    private CurrentUser $currentUser;

    public function testCorrectCredentialsLogInTheEmployee(): void
    {
        assertTrue($this->service()->login('active@mail.test', self::PASSWORD));
        assertSame('1', $this->currentUser->getId());
    }

    #[DataProvider('rejectedLogins')]
    public function testRejectedLoginStaysGuest(string $email, string $password): void
    {
        assertFalse($this->service()->login($email, $password));
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

    public function testOutdatedHashIsRehashedAfterLogin(): void
    {
        assertTrue($this->service()->login('outdated@mail.test', self::PASSWORD));

        $hash = $this->savedHashes[5];
        assertIsString($hash);
        $hasher = new PasswordHasher();
        assertTrue($hasher->validate(self::PASSWORD, $hash));
        assertFalse($hasher->needsRehash($hash));
    }

    public function testCurrentHashIsNotRewritten(): void
    {
        $this->service()->login('active@mail.test', self::PASSWORD);

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
        $service->login('active@mail.test', self::PASSWORD);

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

        return new LoginService($accountRepository, $identityRepository, $hasher, $this->currentUser, new NullSession());
    }
}

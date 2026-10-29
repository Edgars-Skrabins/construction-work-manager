<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AccountRepositoryInterface;
use Yiisoft\Auth\IdentityRepositoryInterface;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

final readonly class LoginService
{
    public const string DUMMY_HASH = '$2y$13$pfiuj5TVZkMKVx15nmVhDeJMBC8Bfml2J7CB4XYbKVx9qQygd5.Tu';

    public function __construct(
        private AccountRepositoryInterface $accounts,
        private IdentityRepositoryInterface $identities,
        private PasswordHasher $passwordHasher,
        private CurrentUser $currentUser,
        private SessionInterface $session,
        private AttemptThrottle $throttle,
    ) {}

    public function login(string $email, string $password, string $clientIp): bool
    {
        if ($password === '') {
            return false;
        }

        $email = mb_strtolower(trim($email));
        $keys = [[ThrottleScope::LoginEmail, $email], [ThrottleScope::LoginIp, $clientIp]];
        $retryAfter = $this->throttle->retryAfter($keys);
        if ($retryAfter !== null) {
            $this->passwordHasher->validate($password, self::DUMMY_HASH);
            throw new TooManyAttemptsException($retryAfter);
        }

        $account = $this->accounts->findByEmail($email);

        if ($account === null || $account->passwordHash === null) {
            $this->passwordHasher->validate($password, self::DUMMY_HASH);
            $this->throttle->recordFailure($keys);
            return false;
        }

        if (!$this->passwordHasher->validate($password, $account->passwordHash) || !$account->isActive) {
            $this->throttle->recordFailure($keys);
            return false;
        }

        $this->throttle->clear(ThrottleScope::LoginEmail, $email);

        if ($this->passwordHasher->needsRehash($account->passwordHash)) {
            $this->accounts->updatePasswordHash($account->employeeId, $this->passwordHasher->hash($password));
        }

        return $this->loginEmployee($account->employeeId);
    }

    public function loginEmployee(int $employeeId): bool
    {
        $identity = $this->identities->findIdentity((string) $employeeId);

        return $identity !== null && $this->currentUser->login($identity);
    }

    public function logout(): void
    {
        $this->currentUser->logout();
        $this->session->destroy();
    }
}

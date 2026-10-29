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
    ) {}

    public function login(string $email, string $password): bool
    {
        if ($password === '') {
            return false;
        }

        $account = $this->accounts->findByEmail($email);

        if ($account === null || $account->passwordHash === null) {
            $this->passwordHasher->validate($password, self::DUMMY_HASH);
            return false;
        }

        if (!$this->passwordHasher->validate($password, $account->passwordHash) || !$account->isActive) {
            return false;
        }

        $identity = $this->identities->findIdentity((string) $account->employeeId);
        if ($identity === null) {
            return false;
        }

        if ($this->passwordHasher->needsRehash($account->passwordHash)) {
            $this->accounts->updatePasswordHash($account->employeeId, $this->passwordHasher->hash($password));
        }

        return $this->currentUser->login($identity);
    }

    public function logout(): void
    {
        $this->currentUser->logout();
        $this->session->destroy();
    }
}

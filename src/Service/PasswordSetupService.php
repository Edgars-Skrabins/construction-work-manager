<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AccountRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Security\PasswordHasher;

final readonly class PasswordSetupService
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private PasswordHasher $passwordHasher,
        private SetupCodeGenerator $setupCodes,
        private LoginService $loginService,
    ) {}

    public function setPassword(string $email, string $code, string $password): bool
    {
        $code = $this->setupCodes->normalize($code);
        $account = $this->accounts->findByEmail($email);

        if ($code === '' || $account === null || $account->setupCodeHash === null) {
            $this->passwordHasher->validate($code === '' ? '-' : $code, LoginService::DUMMY_HASH);
            return false;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (
            !$this->passwordHasher->validate($code, $account->setupCodeHash)
            || !$account->isActive
            || $account->passwordHash !== null
            || $account->setupCodeExpiresAt === null
            || $account->setupCodeExpiresAt <= $now
        ) {
            return false;
        }

        if (!$this->accounts->completeSetup($account->employeeId, $account->setupCodeHash, $this->passwordHasher->hash($password))) {
            return false;
        }

        return $this->loginService->login($email, $password);
    }
}

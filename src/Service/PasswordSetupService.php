<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AccountRepositoryInterface;
use Psr\Clock\ClockInterface;
use Yiisoft\Security\PasswordHasher;

final readonly class PasswordSetupService
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private PasswordHasher $passwordHasher,
        private SetupCodeGenerator $setupCodes,
        private LoginService $loginService,
        private ClockInterface $clock,
    ) {}

    public function setPassword(string $email, string $code, string $password): bool
    {
        $code = $this->setupCodes->normalize($code);
        $account = $this->accounts->findByEmail($email);

        if ($code === '' || $account === null || $account->setupCodeHash === null) {
            $this->passwordHasher->validate($code === '' ? '-' : $code, LoginService::DUMMY_HASH);
            return false;
        }

        $now = $this->clock->now();
        if (
            !$this->passwordHasher->validate($code, $account->setupCodeHash)
            || !$account->isActive
            || $account->passwordHash !== null
            || $account->setupCodeExpiresAt === null
            || $account->setupCodeExpiresAt <= $now
        ) {
            return false;
        }

        if (!$this->accounts->completeSetup($account->employeeId, $account->setupCodeHash, $this->passwordHasher->hash($password), $now)) {
            return false;
        }

        return $this->loginService->loginEmployee($account->employeeId);
    }
}

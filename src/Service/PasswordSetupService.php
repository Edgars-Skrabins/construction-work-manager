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
        private AttemptThrottle $throttle,
    ) {}

    public function setPassword(string $email, string $code, string $password, string $clientIp): bool
    {
        $code = $this->setupCodes->normalize($code);
        $email = mb_strtolower(trim($email));
        $keys = [[ThrottleScope::SetupEmail, $email], [ThrottleScope::SetupIp, $clientIp]];
        $retryAfter = $this->throttle->retryAfter($keys);
        if ($retryAfter !== null) {
            $this->passwordHasher->validate($code === '' ? '-' : $code, LoginService::DUMMY_HASH);
            throw new TooManyAttemptsException($retryAfter);
        }

        $account = $this->accounts->findByEmail($email);

        if ($code === '' || $account === null || $account->setupCodeHash === null) {
            $this->passwordHasher->validate($code === '' ? '-' : $code, LoginService::DUMMY_HASH);
            $this->throttle->recordFailure($keys);
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
            $this->throttle->recordFailure($keys);
            return false;
        }

        if (!$this->accounts->completeSetup($account->employeeId, $account->setupCodeHash, $this->passwordHasher->hash($password), $now)) {
            $this->throttle->recordFailure($keys);
            return false;
        }

        $this->throttle->clear(ThrottleScope::SetupEmail, $email);

        return $this->loginService->loginEmployee($account->employeeId);
    }
}

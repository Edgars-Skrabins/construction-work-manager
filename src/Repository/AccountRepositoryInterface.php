<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use DateTimeImmutable;

interface AccountRepositoryInterface
{
    public function findByEmail(string $email): ?Account;

    public function findByEmployeeId(int $employeeId): ?Account;

    public function updatePasswordHash(int $employeeId, string $passwordHash): void;

    public function create(int $employeeId, string $email, bool $isActive, string $setupCodeHash, DateTimeImmutable $expiresAt): void;

    public function updateEmail(int $employeeId, string $email): void;

    public function setActive(int $employeeId, bool $isActive): void;

    public function replaceSetupCode(int $employeeId, string $setupCodeHash, DateTimeImmutable $expiresAt): void;

    public function completeSetup(int $employeeId, string $passwordHash): void;

    public function delete(int $employeeId): void;
}

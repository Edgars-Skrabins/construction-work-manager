<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;

interface AccountRepositoryInterface
{
    public function findByEmail(string $email): ?Account;

    public function updatePasswordHash(int $employeeId, string $passwordHash): void;
}

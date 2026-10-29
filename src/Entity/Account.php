<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class Account
{
    public function __construct(
        public int $employeeId,
        public string $email,
        public ?string $passwordHash,
        public bool $isActive,
    ) {}
}

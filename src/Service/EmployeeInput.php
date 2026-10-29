<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\Role;
use DateTimeImmutable;

final readonly class EmployeeInput
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public DateTimeImmutable $birthDate,
        public int $accessLevelId,
        public Role $role,
        public ?int $managerId,
        public ?string $email,
        public bool $loginActive,
    ) {}
}

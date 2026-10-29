<?php

declare(strict_types=1);

namespace App\Repository;

use App\Access\Role;
use DateTimeImmutable;

final readonly class EmployeeData
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public DateTimeImmutable $birthDate,
        public int $accessLevelId,
        public Role $role,
        public ?int $managerId,
    ) {}
}

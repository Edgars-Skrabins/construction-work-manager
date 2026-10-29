<?php

declare(strict_types=1);

namespace App\Entity;

use App\Access\Role;
use DateTimeImmutable;

final readonly class Employee
{
    public function __construct(
        public int $id,
        public string $firstName,
        public string $lastName,
        public DateTimeImmutable $birthDate,
        public int $accessLevelId,
        public Role $role,
        public ?int $managerId,
        public ?Account $account,
    ) {}

    public function fullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }
}

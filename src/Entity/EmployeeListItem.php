<?php

declare(strict_types=1);

namespace App\Entity;

use App\Access\Role;
use DateTimeImmutable;

final readonly class EmployeeListItem
{
    public function __construct(
        public int $id,
        public string $firstName,
        public string $lastName,
        public DateTimeImmutable $birthDate,
        public int $accessLevelId,
        public string $accessLevelName,
        public Role $role,
        public ?string $managerName,
        public LoginStatus $loginStatus,
    ) {}

    public function accessLevel(): AccessLevel
    {
        return new AccessLevel($this->accessLevelId, $this->accessLevelName);
    }
}

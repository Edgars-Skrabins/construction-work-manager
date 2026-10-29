<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class EmployeeNeedingAccess
{
    public function __construct(
        public int $employeeId,
        public string $firstName,
        public string $lastName,
        public int $accessLevelId,
        public string $accessLevelName,
    ) {}
}

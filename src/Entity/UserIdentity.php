<?php

declare(strict_types=1);

namespace App\Entity;

use Yiisoft\Auth\IdentityInterface;

final readonly class UserIdentity implements IdentityInterface
{
    public function __construct(
        public int $employeeId,
        public string $firstName,
        public string $lastName,
        public string $role,
    ) {}

    public function getId(): string
    {
        return (string) $this->employeeId;
    }

    public function fullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }
}

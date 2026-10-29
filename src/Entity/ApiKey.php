<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;

final readonly class ApiKey
{
    public function __construct(
        public int $id,
        public string $keyId,
        public string $clientName,
        public string $secretHash,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $lastUsedAt,
        public ?DateTimeImmutable $revokedAt,
    ) {}

    public function isValidAt(DateTimeImmutable $now): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }

        return $this->expiresAt === null || $now < $this->expiresAt;
    }
}

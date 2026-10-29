<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiKey;
use DateTimeImmutable;

interface ApiKeyRepositoryInterface
{
    public function insert(string $keyId, string $clientName, string $secretHash, ?DateTimeImmutable $expiresAt): ApiKey;

    public function findByKeyId(string $keyId): ?ApiKey;

    /**
     * @return list<ApiKey>
     */
    public function findAll(): array;

    public function revoke(string $keyId, DateTimeImmutable $at): bool;

    public function touchLastUsed(int $id, DateTimeImmutable $at): void;
}

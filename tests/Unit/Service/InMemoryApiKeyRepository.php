<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ApiKey;
use App\Repository\ApiKeyRepositoryInterface;
use DateTimeImmutable;

final class InMemoryApiKeyRepository implements ApiKeyRepositoryInterface
{
    /** @var array<string, ApiKey> */
    public array $keys = [];

    /** @var list<string> */
    public array $lookups = [];

    /** @var list<int> */
    public array $touched = [];

    public int $takenLookups = 0;

    public function insert(string $keyId, string $clientName, string $secretHash, ?DateTimeImmutable $expiresAt): ApiKey
    {
        return $this->keys[$keyId] = new ApiKey(
            id: count($this->keys) + 1,
            keyId: $keyId,
            clientName: $clientName,
            secretHash: $secretHash,
            createdAt: new DateTimeImmutable(),
            expiresAt: $expiresAt,
            lastUsedAt: null,
            revokedAt: null,
        );
    }

    public function findByKeyId(string $keyId): ?ApiKey
    {
        $this->lookups[] = $keyId;
        if ($this->takenLookups > 0) {
            $this->takenLookups--;
            return $this->keyWithId($keyId);
        }

        return $this->keys[$keyId] ?? null;
    }

    public function findAll(): array
    {
        return array_values($this->keys);
    }

    public function revoke(string $keyId, DateTimeImmutable $at): bool
    {
        $apiKey = $this->keys[$keyId] ?? null;
        if ($apiKey === null || $apiKey->revokedAt !== null) {
            return false;
        }

        $this->keys[$keyId] = new ApiKey(
            $apiKey->id,
            $apiKey->keyId,
            $apiKey->clientName,
            $apiKey->secretHash,
            $apiKey->createdAt,
            $apiKey->expiresAt,
            $apiKey->lastUsedAt,
            $at,
        );

        return true;
    }

    public function touchLastUsed(int $id, DateTimeImmutable $at): void
    {
        $this->touched[] = $id;
    }

    public function replace(ApiKey $apiKey): void
    {
        $this->keys[$apiKey->keyId] = $apiKey;
    }

    private function keyWithId(string $keyId): ApiKey
    {
        return new ApiKey(99, $keyId, 'Taken', str_repeat('0', 64), new DateTimeImmutable(), null, null, null);
    }
}

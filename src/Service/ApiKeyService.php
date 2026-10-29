<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiKey;
use App\Entity\IssuedApiKey;
use App\Repository\ApiKeyRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ApiKeyService
{
    private const string PREFIX = 'cwm_';
    private const string PATTERN = '/^cwm_([0-9a-f]{8})_([0-9a-f]{64})$/D';
    private const string DUMMY_HASH = '0000000000000000000000000000000000000000000000000000000000000000';
    private const int CLIENT_NAME_MAX_LENGTH = 100;

    public function __construct(
        private ApiKeyRepositoryInterface $repository,
    ) {}

    public function issue(string $clientName, ?DateTimeImmutable $expiresAt): IssuedApiKey
    {
        $clientName = trim($clientName);
        if ($clientName === '') {
            throw new InvalidArgumentException('The client name must not be empty.');
        }
        if (mb_strlen($clientName) > self::CLIENT_NAME_MAX_LENGTH) {
            throw new InvalidArgumentException('The client name must be at most 100 characters.');
        }
        if ($expiresAt !== null && $expiresAt <= $this->now()) {
            throw new InvalidArgumentException('The expiry date must be in the future.');
        }

        do {
            $keyId = bin2hex(random_bytes(4));
        } while ($this->repository->findByKeyId($keyId) !== null);

        $secret = bin2hex(random_bytes(32));
        $apiKey = $this->repository->insert($keyId, $clientName, hash('sha256', $secret), $expiresAt);

        return new IssuedApiKey($apiKey, self::PREFIX . $keyId . '_' . $secret);
    }

    public function authenticate(string $plainKey): ?ApiKey
    {
        if (preg_match(self::PATTERN, $plainKey, $matches) !== 1) {
            return null;
        }

        $apiKey = $this->repository->findByKeyId($matches[1]);
        $matchesHash = hash_equals($apiKey->secretHash ?? self::DUMMY_HASH, hash('sha256', $matches[2]));

        $now = $this->now();
        if ($apiKey === null || !$matchesHash || !$apiKey->isValidAt($now)) {
            return null;
        }

        $this->repository->touchLastUsed($apiKey->id, $now);

        return $apiKey;
    }

    public function revoke(string $keyId): bool
    {
        return $this->repository->revoke($keyId, $this->now());
    }

    /**
     * @return list<ApiKey>
     */
    public function list(): array
    {
        return $this->repository->findAll();
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

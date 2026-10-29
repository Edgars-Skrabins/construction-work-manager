<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\ApiKeyRepository;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class ApiKeyRepositoryTest extends IntegrationTestCase
{
    private const string HASH = 'a3f1c2b4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f80';

    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testInsertedKeyIsFoundByKeyId(): void
    {
        $expiresAt = new DateTimeImmutable('2027-01-01 00:00:00', new DateTimeZone('UTC'));

        $inserted = $this->repository()->insert('0a1b2c3d', 'Ziemeļu vārti', self::HASH, $expiresAt);
        $found = $this->repository()->findByKeyId('0a1b2c3d');

        assertNotNull($found);
        assertEquals($inserted, $found);
        assertSame('Ziemeļu vārti', $found->clientName);
        assertSame(self::HASH, $found->secretHash);
        assertEquals($expiresAt, $found->expiresAt);
        assertNull($found->lastUsedAt);
        assertNull($found->revokedAt);
    }

    public function testExpiryGivenInAnotherTimeZoneIsStoredAsUtc(): void
    {
        $this->repository()->insert('0a1b2c3d', 'North Gate', self::HASH, new DateTimeImmutable('2027-01-01 02:00:00', new DateTimeZone('Europe/Riga')));

        assertSame('2027-01-01 00:00:00', $this->repository()->findByKeyId('0a1b2c3d')?->expiresAt?->format('Y-m-d H:i:s'));
    }

    public function testUnknownKeyIdReturnsNull(): void
    {
        assertNull($this->repository()->findByKeyId('ffffffff'));
    }

    public function testFindAllIsOrderedByCreationThenId(): void
    {
        $this->repository()->insert('0000000b', 'Second', self::HASH, null);
        $this->repository()->insert('0000000a', 'Third', self::HASH, null);
        $this->db()->createCommand("UPDATE API_KEY SET CREATED_AT = '2020-01-01' WHERE KEY_ID = N'0000000a'")->execute();

        $keyIds = array_map(static fn($apiKey): string => $apiKey->keyId, $this->repository()->findAll());

        assertSame(['0000000a', '0000000b'], array_slice($keyIds, 0, 2));
    }

    public function testRevokeOnlyOnce(): void
    {
        $at = new DateTimeImmutable('2026-10-29 12:00:00', new DateTimeZone('UTC'));
        $this->repository()->insert('0a1b2c3d', 'North Gate', self::HASH, null);

        assertTrue($this->repository()->revoke('0a1b2c3d', $at));
        assertFalse($this->repository()->revoke('0a1b2c3d', $at->modify('+1 hour')));
        assertFalse($this->repository()->revoke('ffffffff', $at));
        assertEquals($at, $this->repository()->findByKeyId('0a1b2c3d')?->revokedAt);
    }

    public function testTouchLastUsed(): void
    {
        $at = new DateTimeImmutable('2026-10-29 12:00:00', new DateTimeZone('UTC'));
        $apiKey = $this->repository()->insert('0a1b2c3d', 'North Gate', self::HASH, null);

        $this->repository()->touchLastUsed($apiKey->id, $at);

        assertEquals($at, $this->repository()->findByKeyId('0a1b2c3d')?->lastUsedAt);
    }

    private function repository(): ApiKeyRepository
    {
        return $this->service(ApiKeyRepository::class);
    }
}

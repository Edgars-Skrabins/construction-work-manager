<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ApiKey;
use App\Entity\ApiKeyStatus;
use App\Service\ApiKeyService;
use App\Tests\FixedClock;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

final class ApiKeyServiceTest extends TestCase
{
    private const string NOW = '2026-10-29 12:00:00 Europe/Riga';

    private InMemoryApiKeyRepository $repository;

    private ApiKeyService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryApiKeyRepository();
        $this->service = new ApiKeyService($this->repository, new FixedClock(new DateTimeImmutable(self::NOW)));
    }

    public function testIssuedKeyHasTheFormatAndOnlyItsHashIsStored(): void
    {
        $issued = $this->service->issue('  North Gate  ', self::at('+1 day'));

        assertMatchesRegularExpression('/^cwm_[0-9a-f]{8}_[0-9a-f]{64}$/', $issued->plainKey);
        [, $keyId, $secret] = explode('_', $issued->plainKey);
        $stored = $this->repository->keys[$keyId];
        assertSame('North Gate', $stored->clientName);
        assertSame(hash('sha256', $secret), $stored->secretHash);
        assertStringNotContainsString($secret, serialize($stored));
        assertSame($stored, $issued->apiKey);
    }

    #[DataProvider('invalidIssues')]
    public function testInvalidIssueIsRejected(string $clientName, ?string $expiresAt): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->issue($clientName, $expiresAt === null ? null : self::at($expiresAt));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function invalidIssues(): iterable
    {
        yield 'empty name' => ['', null];
        yield 'blank name' => ['   ', null];
        yield 'too long name' => [str_repeat('ā', 101), null];
        yield 'past expiry' => ['North Gate', '-1 day'];
        yield 'expiry exactly now' => ['North Gate', '+0 seconds'];
    }

    public function testNameOfExactlyTheMaximumLengthIsAccepted(): void
    {
        assertSame(str_repeat('ā', 100), $this->service->issue(str_repeat('ā', 100), null)->apiKey->clientName);
    }

    public function testTakenKeyIdIsRegenerated(): void
    {
        $this->repository->takenLookups = 1;

        $issued = $this->service->issue('North Gate', null);

        assertCount(2, $this->repository->lookups);
        assertNotSame($this->repository->lookups[0], $issued->apiKey->keyId);
        assertSame($this->repository->lookups[1], $issued->apiKey->keyId);
    }

    public function testIssuedKeyAuthenticatesAndTouchesLastUsed(): void
    {
        $issued = $this->service->issue('North Gate', null);

        $apiKey = $this->service->authenticate($issued->plainKey);

        assertNotNull($apiKey);
        assertSame($issued->apiKey->id, $apiKey->id);
        assertSame([$issued->apiKey->id], $this->repository->touched);
        assertSame('2026-10-29 10:00:00 UTC', $this->repository->touchedAt?->format('Y-m-d H:i:s e'));
    }

    /**
     * @param callable(string, InMemoryApiKeyRepository, ApiKey): string $tamper
     */
    #[DataProvider('rejectedKeys')]
    public function testRejectedKeyReturnsNullWithoutTouching(callable $tamper): void
    {
        $issued = $this->service->issue('North Gate', null);
        $plainKey = $tamper($issued->plainKey, $this->repository, $issued->apiKey);

        assertNull($this->service->authenticate($plainKey));
        assertSame([], $this->repository->touched);
    }

    /**
     * @return iterable<string, array{callable(string, InMemoryApiKeyRepository, ApiKey): string}>
     */
    public static function rejectedKeys(): iterable
    {
        yield 'empty' => [static fn(): string => ''];
        yield 'wrong prefix' => [static fn(string $key): string => 'xyz_' . substr($key, 4)];
        yield 'uppercase' => [static fn(string $key): string => strtoupper($key)];
        yield 'trailing newline' => [static fn(string $key): string => $key . "\n"];
        yield 'unknown key ID' => [static fn(string $key): string => 'cwm_ffffffff' . substr($key, 12)];
        yield 'wrong secret' => [static fn(string $key): string => substr($key, 0, -1) . (str_ends_with($key, '0') ? '1' : '0')];
        yield 'revoked' => [static function (string $key, InMemoryApiKeyRepository $repository, ApiKey $apiKey): string {
            $repository->revoke($apiKey->keyId, new DateTimeImmutable());
            return $key;
        }];
        yield 'expired' => [static function (string $key, InMemoryApiKeyRepository $repository, ApiKey $apiKey): string {
            $repository->replace(new ApiKey(
                $apiKey->id,
                $apiKey->keyId,
                $apiKey->clientName,
                $apiKey->secretHash,
                $apiKey->createdAt,
                self::at('-1 second'),
                null,
                null,
            ));
            return $key;
        }];
    }

    public function testRevokeAndList(): void
    {
        $issued = $this->service->issue('North Gate', null);

        assertTrue($this->service->revoke($issued->apiKey->keyId));
        assertFalse($this->service->revoke($issued->apiKey->keyId));
        assertNotNull($this->service->list()[0]->revokedAt);
    }

    public function testStatus(): void
    {
        $active = $this->service->issue('North Gate', null)->apiKey;
        $expired = new ApiKey(2, '0000000e', 'Old', str_repeat('0', 64), self::at('-2 days'), self::at('-1 day'), null, null);
        $revoked = new ApiKey(3, '0000000f', 'Gone', str_repeat('0', 64), self::at('-2 days'), self::at('-1 day'), null, self::at('-1 day'));

        assertSame(ApiKeyStatus::Active, $this->service->status($active));
        assertSame(ApiKeyStatus::Expired, $this->service->status($expired));
        assertSame(ApiKeyStatus::Revoked, $this->service->status($revoked));
    }

    private static function at(string $modifier): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::NOW))->modify($modifier);
    }
}

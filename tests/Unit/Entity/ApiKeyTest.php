<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ApiKey;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class ApiKeyTest extends TestCase
{
    private const string NOW = '2026-10-29 12:00:00';

    #[DataProvider('validity')]
    public function testIsValidAt(?string $expiresAt, ?string $revokedAt, bool $expected): void
    {
        $apiKey = new ApiKey(
            id: 1,
            keyId: '0a1b2c3d',
            clientName: 'North Gate',
            secretHash: str_repeat('a', 64),
            createdAt: new DateTimeImmutable('2026-01-01 00:00:00'),
            expiresAt: $expiresAt === null ? null : new DateTimeImmutable($expiresAt),
            lastUsedAt: null,
            revokedAt: $revokedAt === null ? null : new DateTimeImmutable($revokedAt),
        );

        assertSame($expected, $apiKey->isValidAt(new DateTimeImmutable(self::NOW)));
    }

    /**
     * @return iterable<string, array{string|null, string|null, bool}>
     */
    public static function validity(): iterable
    {
        yield 'no expiry' => [null, null, true];
        yield 'expires later' => ['2026-10-29 12:00:01', null, true];
        yield 'expires exactly now' => [self::NOW, null, false];
        yield 'expired' => ['2026-10-28 00:00:00', null, false];
        yield 'revoked' => [null, '2026-10-01 00:00:00', false];
        yield 'revoked before expiry' => ['2027-01-01 00:00:00', '2026-10-01 00:00:00', false];
    }
}

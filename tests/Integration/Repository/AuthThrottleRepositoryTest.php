<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\AuthThrottleRepository;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class AuthThrottleRepositoryTest extends IntegrationTestCase
{
    private const string HASH = 'a3f1c2b4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f80';
    private const string OTHER_HASH = '0b1c2d3e4f5061728394a5b6c7d8e9f00112233445566778899aabbccddeeff0';

    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testFirstFailureStartsAWindowInUtc(): void
    {
        $this->record('2026-10-29 10:00:00');

        $throttle = $this->repository()->find('login.email', self::HASH);
        assertSame(1, $throttle?->failures);
        assertSame('2026-10-29 08:00:00', $throttle->windowStart->format('Y-m-d H:i:s'));
    }

    public function testFailuresWithinTheWindowAreCounted(): void
    {
        $this->record('2026-10-29 10:00:00');
        $this->record('2026-10-29 10:05:00');
        $this->record('2026-10-29 10:14:59');

        $throttle = $this->repository()->find('login.email', self::HASH);
        assertSame(3, $throttle?->failures);
        assertSame('2026-10-29 08:00:00', $throttle->windowStart->format('Y-m-d H:i:s'));
    }

    public function testFailureAfterTheWindowStartsANewOne(): void
    {
        $this->record('2026-10-29 10:00:00');
        $this->record('2026-10-29 10:05:00');
        $this->record('2026-10-29 10:15:00');

        $throttle = $this->repository()->find('login.email', self::HASH);
        assertSame(1, $throttle?->failures);
        assertSame('2026-10-29 08:15:00', $throttle->windowStart->format('Y-m-d H:i:s'));
    }

    public function testRecordingAFailureDeletesExpiredRowsOfEveryScope(): void
    {
        $this->record('2026-10-29 10:00:00', 'login.ip', self::OTHER_HASH);
        $this->record('2026-10-29 10:00:00', 'api.ip');
        $this->record('2026-10-29 10:05:00', 'setup.ip', self::OTHER_HASH);

        $this->record('2026-10-29 10:15:00');

        assertNull($this->repository()->find('login.ip', self::OTHER_HASH));
        assertNull($this->repository()->find('api.ip', self::HASH));
        assertSame(1, $this->repository()->find('setup.ip', self::OTHER_HASH)?->failures);
        assertSame(1, $this->repository()->find('login.email', self::HASH)?->failures);
    }

    public function testScopesAreCountedSeparately(): void
    {
        $this->record('2026-10-29 10:00:00');
        $this->record('2026-10-29 10:01:00', 'setup.email');

        assertSame(1, $this->repository()->find('login.email', self::HASH)?->failures);
        assertSame(1, $this->repository()->find('setup.email', self::HASH)?->failures);
    }

    public function testClearDeletesTheRow(): void
    {
        $this->record('2026-10-29 10:00:00');
        $this->record('2026-10-29 10:00:00', 'setup.email');

        $this->repository()->clear('login.email', self::HASH);

        assertNull($this->repository()->find('login.email', self::HASH));
        assertSame(1, $this->repository()->find('setup.email', self::HASH)?->failures);
    }

    private function record(string $time, string $scope = 'login.email', string $hash = self::HASH): void
    {
        $now = new DateTimeImmutable($time, new DateTimeZone('Europe/Riga'));

        $this->repository()->recordFailure($scope, $hash, $now, $now->modify('-15 minutes'));
    }

    private function repository(): AuthThrottleRepository
    {
        return $this->service(AuthThrottleRepository::class);
    }
}

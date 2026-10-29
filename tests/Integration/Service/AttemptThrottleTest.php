<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Repository\AuthThrottleRepositoryInterface;
use App\Service\AttemptThrottle;
use App\Service\ThrottleLimits;
use App\Service\ThrottleScope;
use App\Tests\FixedClock;
use App\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class AttemptThrottleTest extends IntegrationTestCase
{
    private const string EMAIL = 'admin@mail.test';
    private const string IP = '203.0.113.7';

    protected function setUp(): void
    {
        $this->db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->db()->getTransaction()?->rollBack();
    }

    public function testKeyIsAllowedBelowTheLimit(): void
    {
        $this->failTimes(ThrottleScope::LoginEmail, self::EMAIL, 4, '10:00:00');

        assertNull($this->throttleAt('10:00:30')->retryAfter([[ThrottleScope::LoginEmail, self::EMAIL]]));
    }

    public function testKeyIsBlockedAtTheLimitUntilTheWindowEnds(): void
    {
        $this->failTimes(ThrottleScope::LoginEmail, self::EMAIL, 2, '10:00:00');
        $this->failTimes(ThrottleScope::LoginEmail, self::EMAIL, 3, '10:10:00');

        assertSame(900, $this->throttleAt('10:00:00')->retryAfter([[ThrottleScope::LoginEmail, self::EMAIL]]));
        assertSame(1, $this->throttleAt('10:14:59')->retryAfter([[ThrottleScope::LoginEmail, self::EMAIL]]));
        assertNull($this->throttleAt('10:15:00')->retryAfter([[ThrottleScope::LoginEmail, self::EMAIL]]));
    }

    public function testFailureAfterTheWindowRestartsTheCount(): void
    {
        $this->failTimes(ThrottleScope::LoginEmail, self::EMAIL, 5, '10:00:00');

        $this->failTimes(ThrottleScope::LoginEmail, self::EMAIL, 4, '10:15:00');

        assertNull($this->throttleAt('10:15:00')->retryAfter([[ThrottleScope::LoginEmail, self::EMAIL]]));
        $this->failTimes(ThrottleScope::LoginEmail, self::EMAIL, 1, '10:16:00');
        assertSame(900, $this->throttleAt('10:15:00')->retryAfter([[ThrottleScope::LoginEmail, self::EMAIL]]));
    }

    public function testClearResetsTheKey(): void
    {
        $this->failTimes(ThrottleScope::LoginEmail, self::EMAIL, 5, '10:00:00');

        $this->throttleAt('10:01:00')->clear(ThrottleScope::LoginEmail, self::EMAIL);

        assertNull($this->throttleAt('10:01:00')->retryAfter([[ThrottleScope::LoginEmail, self::EMAIL]]));
    }

    public function testScopesAndKeysAreIndependent(): void
    {
        $this->failTimes(ThrottleScope::LoginEmail, self::EMAIL, 5, '10:00:00');

        $throttle = $this->throttleAt('10:01:00');
        assertNull($throttle->retryAfter([[ThrottleScope::SetupEmail, self::EMAIL]]));
        assertNull($throttle->retryAfter([[ThrottleScope::LoginEmail, 'employee@mail.test']]));
    }

    public function testIpScopesUseTheirOwnLimits(): void
    {
        $this->failTimes(ThrottleScope::LoginIp, self::IP, 5, '10:00:00');
        $this->failTimes(ThrottleScope::ApiIp, self::IP, 2, '10:00:00');

        assertNull($this->throttleAt('10:01:00')->retryAfter([[ThrottleScope::LoginIp, self::IP]]));
        assertSame(840, $this->throttleAt('10:01:00')->retryAfter([[ThrottleScope::ApiIp, self::IP]]));
    }

    public function testLongestWaitWinsOverSeveralKeys(): void
    {
        $this->failTimes(ThrottleScope::LoginEmail, self::EMAIL, 5, '10:00:00');
        $this->failTimes(ThrottleScope::LoginIp, self::IP, 20, '10:05:00');

        assertSame(840, $this->throttleAt('10:06:00')->retryAfter([
            [ThrottleScope::LoginEmail, self::EMAIL],
            [ThrottleScope::LoginIp, self::IP],
        ]));
    }

    public function testKeysAreStoredHashed(): void
    {
        $this->failTimes(ThrottleScope::LoginEmail, self::EMAIL, 1, '10:00:00');

        $repository = $this->service(AuthThrottleRepositoryInterface::class);
        assertNotNull($repository->find('login.email', hash('sha256', self::EMAIL)));
        assertNull($repository->find('login.email', self::EMAIL));
    }

    private function failTimes(ThrottleScope $scope, string $key, int $times, string $time): void
    {
        $throttle = $this->throttleAt($time);
        for ($i = 0; $i < $times; $i++) {
            $throttle->recordFailure([[$scope, $key]]);
        }
    }

    private function throttleAt(string $time): AttemptThrottle
    {
        return new AttemptThrottle(
            $this->service(AuthThrottleRepositoryInterface::class),
            new ThrottleLimits(windowMinutes: 15, emailLimit: 5, ipLimit: 20, apiIpLimit: 2),
            new FixedClock(new DateTimeImmutable('2026-10-29 ' . $time, new DateTimeZone('Europe/Riga'))),
        );
    }
}

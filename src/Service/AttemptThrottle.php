<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AuthThrottleRepositoryInterface;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final readonly class AttemptThrottle
{
    public function __construct(
        private AuthThrottleRepositoryInterface $repository,
        private ThrottleLimits $limits,
        private ClockInterface $clock,
    ) {}

    /**
     * @param list<array{ThrottleScope, string}> $keys
     */
    public function retryAfter(array $keys): ?int
    {
        $now = $this->clock->now();
        $retryAfter = null;

        foreach ($keys as [$scope, $key]) {
            $throttle = $this->repository->find($scope->value, self::hash($key));
            if ($throttle === null || $throttle->failures < $this->limits->limit($scope)) {
                continue;
            }

            $seconds = $this->windowEnd($throttle->windowStart)->getTimestamp() - $now->getTimestamp();
            if ($seconds > 0) {
                $retryAfter = max($retryAfter ?? 0, $seconds);
            }
        }

        return $retryAfter;
    }

    public function recordFailure(ThrottleScope $scope, string $key): void
    {
        $now = $this->clock->now();

        $this->repository->recordFailure(
            $scope->value,
            self::hash($key),
            $now,
            $now->modify(sprintf('-%d minutes', $this->limits->windowMinutes)),
        );
    }

    public function clear(ThrottleScope $scope, string $key): void
    {
        $this->repository->clear($scope->value, self::hash($key));
    }

    private function windowEnd(DateTimeImmutable $windowStart): DateTimeImmutable
    {
        return $windowStart->modify(sprintf('+%d minutes', $this->limits->windowMinutes));
    }

    private static function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}

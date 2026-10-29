<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuthThrottle;
use DateTimeImmutable;

interface AuthThrottleRepositoryInterface
{
    public function find(string $scope, string $keyHash): ?AuthThrottle;

    public function recordFailure(string $scope, string $keyHash, DateTimeImmutable $now, DateTimeImmutable $windowStartsAfter): void;

    public function clear(string $scope, string $keyHash): void;
}

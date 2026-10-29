<?php

declare(strict_types=1);

namespace App\Service;

final readonly class ThrottleLimits
{
    public function __construct(
        public int $windowMinutes,
        public int $emailLimit,
        public int $ipLimit,
        public int $apiIpLimit,
    ) {}

    public function limit(ThrottleScope $scope): int
    {
        return match ($scope) {
            ThrottleScope::LoginEmail, ThrottleScope::SetupEmail => $this->emailLimit,
            ThrottleScope::LoginIp, ThrottleScope::SetupIp => $this->ipLimit,
            ThrottleScope::ApiIp => $this->apiIpLimit,
        };
    }
}

<?php

declare(strict_types=1);

use App\Service\ThrottleLimits;
use App\Shared\ApplicationParams;
use App\Shared\Clock;
use Psr\Clock\ClockInterface;

/** @var array{application: array{name: string, charset: string, locale: string, timezone: string}, authThrottle: array{windowMinutes: int, emailLimit: int, ipLimit: int, apiIpLimit: int}} $params */

return [
    ApplicationParams::class => [
        '__construct()' => [
            'name' => $params['application']['name'],
            'charset' => $params['application']['charset'],
            'locale' => $params['application']['locale'],
        ],
    ],
    ThrottleLimits::class => [
        '__construct()' => $params['authThrottle'],
    ],
    ClockInterface::class => [
        'class' => Clock::class,
        '__construct()' => [
            'timezone' => $params['application']['timezone'],
        ],
    ],
];

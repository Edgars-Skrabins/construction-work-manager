<?php

declare(strict_types=1);

use App\Shared\ApplicationParams;
use App\Shared\Clock;
use Psr\Clock\ClockInterface;

/** @var array{application: array{name: string, charset: string, timezone: string}} $params */

return [
    ApplicationParams::class => [
        '__construct()' => [
            'name' => $params['application']['name'],
            'charset' => $params['application']['charset'],
        ],
    ],
    ClockInterface::class => [
        'class' => Clock::class,
        '__construct()' => [
            'timezone' => $params['application']['timezone'],
        ],
    ],
];

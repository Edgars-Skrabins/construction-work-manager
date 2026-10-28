<?php

declare(strict_types=1);

use App\Shared\ApplicationParams;

/** @var array{application: array{name: string, charset: string, locale: string}} $params */

return [
    ApplicationParams::class => [
        '__construct()' => [
            'name' => $params['application']['name'],
            'charset' => $params['application']['charset'],
            'locale' => $params['application']['locale'],
        ],
    ],
];

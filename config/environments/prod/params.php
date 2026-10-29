<?php

declare(strict_types=1);

return [
    'app/language' => [
        'cookieSecure' => true,
    ],

    'yiisoft/session' => [
        'session' => [
            'options' => [
                'cookie_secure' => 1,
            ],
        ],
    ],
];

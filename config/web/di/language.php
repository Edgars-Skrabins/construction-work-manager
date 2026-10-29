<?php

declare(strict_types=1);

use App\Web\Language\LanguageCookie;

/** @var array{'app/language': array{cookieSecure: bool}} $params */

return [
    LanguageCookie::class => [
        '__construct()' => [
            'secure' => $params['app/language']['cookieSecure'],
        ],
    ],
];

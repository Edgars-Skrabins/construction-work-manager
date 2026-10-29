<?php

declare(strict_types=1);

use Yiisoft\Aliases\Aliases;
use Yiisoft\Translator\CategorySource;
use Yiisoft\Translator\IntlMessageFormatter;
use Yiisoft\Translator\Message\Php\MessageSource;

return [
    'translation.app' => [
        'definition' => static function (Aliases $aliases): CategorySource {
            $source = new MessageSource($aliases->get('@messages'));

            return new CategorySource('app', $source, new IntlMessageFormatter());
        },
        'tags' => ['translation.categorySource'],
    ],
];

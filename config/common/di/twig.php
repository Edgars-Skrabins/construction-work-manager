<?php

declare(strict_types=1);

use App\Web\Shared\Language\TranslatorExtension;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Definitions\Reference;

/** @var array{'yiisoft/view-twig': array{options: array<string, mixed>}} $params */

return [
    LoaderInterface::class => static fn(Aliases $aliases): LoaderInterface => new FilesystemLoader($aliases->get('@src')),
    Environment::class => [
        '__construct()' => [
            'options' => $params['yiisoft/view-twig']['options'],
        ],
        'addExtension()' => [Reference::to(TranslatorExtension::class)],
    ],
];

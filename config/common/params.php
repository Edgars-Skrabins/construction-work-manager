<?php

declare(strict_types=1);

use App\Environment;
use App\Shared\ApplicationParams;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Assets\AssetManager;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\View\Twig\TwigTemplateRenderer;
use Yiisoft\Yii\View\Renderer\CsrfViewInjection;

return [
    'application' => require __DIR__ . '/application.php',

    'yiisoft/aliases' => [
        'aliases' => require __DIR__ . '/aliases.php',
    ],

    'yiisoft/view' => [
        'basePath' => '@src',
        'fallbackExtension' => 'twig',
        'renderers' => [
            'twig' => Reference::to(TwigTemplateRenderer::class),
        ],
        'parameters' => [
            'assetManager' => Reference::to(AssetManager::class),
            'applicationParams' => Reference::to(ApplicationParams::class),
            'aliases' => Reference::to(Aliases::class),
            'urlGenerator' => Reference::to(UrlGeneratorInterface::class),
            'currentRoute' => Reference::to(CurrentRoute::class),
        ],
    ],

    'yiisoft/view-twig' => [
        'options' => [
            'cache' => DynamicReference::to(static fn(Aliases $aliases): string => $aliases->get('@runtime/cache/twig')),
            'charset' => 'utf-8',
            'debug' => Environment::appDebug(),
            'strict_variables' => true,
        ],
    ],

    'yiisoft/yii-view-renderer' => [
        'viewPath' => null,
        'layout' => '@src/Web/Shared/Layout/Main/layout.twig',
        'injections' => [
            Reference::to(CsrfViewInjection::class),
        ],
    ],
];

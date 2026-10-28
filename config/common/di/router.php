<?php

declare(strict_types=1);

use Yiisoft\Config\Config;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollectionInterface;
use Yiisoft\Router\RouteCollector;
use Yiisoft\Router\RouteCollectorInterface;

/** @var Config $config */

return [
    RouteCollectionInterface::class => [
        'class' => RouteCollection::class,
        '__construct()' => [
            'collector' => DynamicReference::to(
                static function () use ($config): RouteCollectorInterface {
                    /** @var list<Group|Route> $routes */
                    $routes = $config->get('routes');

                    return (new RouteCollector())->addRoute(...$routes);
                },
            ),
        ],
    ],
];

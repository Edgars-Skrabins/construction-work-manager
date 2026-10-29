<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Environment;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

abstract class IntegrationTestCase extends TestCase
{
    private ?ContainerInterface $container = null;

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    final protected function service(string $id): object
    {
        $this->container ??= (new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        ))->getContainer();

        $service = $this->container->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    final protected function resetServices(): void
    {
        $this->container = null;
    }

    final protected function db(): ConnectionInterface
    {
        return $this->service(ConnectionInterface::class);
    }
}

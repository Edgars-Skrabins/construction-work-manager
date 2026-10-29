<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Environment;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use ReflectionProperty;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Definitions\ArrayDefinition;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertIsArray;

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

    /**
     * @param array<string, string> $headers
     */
    final protected function handleRequest(string $method, string $path, array $headers = []): ResponseInterface
    {
        $request = $this->service(ServerRequestFactoryInterface::class)->createServerRequest($method, 'http://localhost' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->service(Application::class)->handle($request);
    }

    /**
     * @return list<mixed>
     */
    final protected function webMiddlewares(): array
    {
        $params = [];
        /** @var array<string, array{'__construct()': array{dispatcher: DynamicReference}}> $definitions */
        $definitions = require dirname(__DIR__, 2) . '/config/web/di/application.php';
        $dispatcher = $definitions[Application::class]['__construct()']['dispatcher'];
        $definition = (new ReflectionProperty($dispatcher, 'definition'))->getValue($dispatcher);
        assertInstanceOf(ArrayDefinition::class, $definition);
        $arguments = $definition->getMethodsAndProperties()['withMiddlewares()'][2] ?? null;
        assertIsArray($arguments);
        assertIsArray($arguments[0]);

        return array_values($arguments[0]);
    }
}

<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\Router\Middleware\Router;

final readonly class ApiPipelineMiddleware implements MiddlewareInterface
{
    public function __construct(
        private MiddlewareDispatcher $dispatcher,
        private ApiNotFoundHandler $notFoundHandler,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!self::isApiPath($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        return $this->dispatcher
            ->withMiddlewares([ApiErrorMiddleware::class, ApiKeyAuthentication::class, Router::class])
            ->dispatch($request, $this->notFoundHandler);
    }

    public static function isApiPath(string $path): bool
    {
        $path = urldecode($path);

        return $path === '/api' || str_starts_with($path, '/api/');
    }
}

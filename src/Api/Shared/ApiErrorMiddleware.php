<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Http\Status;

final readonly class ApiErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JsonResponseFactory $responseFactory,
        private LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $this->logger->error('Unhandled API error.', [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->responseFactory->problem(Status::INTERNAL_SERVER_ERROR);
        }

        if ($response->getStatusCode() === Status::METHOD_NOT_ALLOWED && $response->getBody()->getSize() === 0) {
            return $this->responseFactory->problem(Status::METHOD_NOT_ALLOWED, null, ['Allow' => $response->getHeaderLine('Allow')]);
        }

        return $response;
    }
}

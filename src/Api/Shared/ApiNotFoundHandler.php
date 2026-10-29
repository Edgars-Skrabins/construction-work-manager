<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;

final readonly class ApiNotFoundHandler implements RequestHandlerInterface
{
    public function __construct(
        private JsonResponseFactory $responseFactory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responseFactory->problem(Status::NOT_FOUND, 'Not found.');
    }
}

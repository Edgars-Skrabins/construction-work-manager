<?php

declare(strict_types=1);

namespace App\Web\Shared\Modal;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;

final readonly class ModalRedirect implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $status = $response->getStatusCode();

        if ($request->getHeaderLine('X-Modal') !== '1' || $status < 300 || $status > 399 || !$response->hasHeader('Location')) {
            return $response;
        }

        return $response
            ->withStatus(Status::NO_CONTENT)
            ->withHeader('X-Modal-Location', $response->getHeaderLine('Location'))
            ->withoutHeader('Location');
    }
}

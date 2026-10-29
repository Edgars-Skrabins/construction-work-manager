<?php

declare(strict_types=1);

namespace App\Tests;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CallableRequestHandler implements RequestHandlerInterface
{
    /**
     * @param Closure(ServerRequestInterface): ResponseInterface $handle
     */
    public function __construct(
        private Closure $handle,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->handle)($request);
    }
}

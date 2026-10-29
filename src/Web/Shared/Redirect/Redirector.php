<?php

declare(strict_types=1);

namespace App\Web\Shared\Redirect;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Stringable;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;

final readonly class Redirector
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * @param array<string, scalar|Stringable|null> $arguments
     */
    public function toRoute(string $name, array $arguments = []): ResponseInterface
    {
        return $this->to($this->urlGenerator->generate($name, $arguments));
    }

    public function to(string $url): ResponseInterface
    {
        return $this->responseFactory->createResponse(Status::FOUND)->withHeader('Location', $url);
    }
}

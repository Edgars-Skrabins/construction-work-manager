<?php

declare(strict_types=1);

namespace App\Web\Forbidden;

use App\Access\ForbiddenException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class ForbiddenMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private WebViewRenderer $viewRenderer,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ForbiddenException) {
            return $this->viewRenderer
                ->render(__DIR__ . '/template', ['urlGenerator' => $this->urlGenerator])
                ->withStatus(Status::FORBIDDEN);
        }
    }
}

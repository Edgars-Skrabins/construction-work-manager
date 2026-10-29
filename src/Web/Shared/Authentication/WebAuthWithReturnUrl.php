<?php

declare(strict_types=1);

namespace App\Web\Shared\Authentication;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Auth\AuthenticationMethodInterface;
use Yiisoft\Auth\IdentityInterface;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\User\CurrentUser;

final readonly class WebAuthWithReturnUrl implements AuthenticationMethodInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ReturnUrl $returnUrl,
        private ResponseFactoryInterface $responseFactory,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function authenticate(ServerRequestInterface $request): ?IdentityInterface
    {
        if (!$this->currentUser->isGuest()) {
            return $this->currentUser->getIdentity();
        }

        if ($request->getMethod() === Method::GET) {
            $uri = $request->getUri();
            $query = $uri->getQuery();
            $this->returnUrl->remember($uri->getPath() . ($query === '' ? '' : '?' . $query));
        }

        return null;
    }

    public function challenge(ResponseInterface $response): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(Status::FOUND)
            ->withHeader('Location', $this->urlGenerator->generate('login'));
    }
}

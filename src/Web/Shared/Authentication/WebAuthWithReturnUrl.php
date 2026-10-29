<?php

declare(strict_types=1);

namespace App\Web\Shared\Authentication;

use App\Web\Shared\Redirect\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Auth\AuthenticationMethodInterface;
use Yiisoft\Auth\IdentityInterface;
use Yiisoft\Http\Method;
use Yiisoft\User\CurrentUser;

final readonly class WebAuthWithReturnUrl implements AuthenticationMethodInterface
{
    public function __construct(
        private Redirector $redirector,
        private CurrentUser $currentUser,
        private ReturnUrl $returnUrl,
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
        return $this->redirector->toRoute('login');
    }
}

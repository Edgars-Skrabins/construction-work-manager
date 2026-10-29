<?php

declare(strict_types=1);

namespace App\Web\Logout;

use App\Service\LoginService;
use App\Web\Shared\Redirect\Redirector;
use Psr\Http\Message\ResponseInterface;

final readonly class Action
{
    public function __construct(
        private Redirector $redirector,
        private LoginService $loginService,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $this->loginService->logout();

        return $this->redirector->toRoute('login');
    }
}

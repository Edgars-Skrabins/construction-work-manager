<?php

declare(strict_types=1);

namespace App\Web\Login;

use App\Service\LoginService;
use App\Web\Shared\Authentication\ReturnUrl;
use App\Web\Shared\Redirect\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Method;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class Action
{
    public function __construct(
        private Redirector $redirector,
        private WebViewRenderer $viewRenderer,
        private CurrentUser $currentUser,
        private LoginService $loginService,
        private ReturnUrl $returnUrl,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->currentUser->isGuest()) {
            return $this->redirector->toRoute('home');
        }

        $email = '';
        $error = null;

        if ($request->getMethod() === Method::POST) {
            $body = $request->getParsedBody();
            $email = is_array($body) && is_string($body['email'] ?? null) ? $body['email'] : '';
            $password = is_array($body) && is_string($body['password'] ?? null) ? $body['password'] : '';

            if (trim($email) === '' || $password === '') {
                $error = 'Enter your email and password.';
            } elseif ($this->loginService->login($email, $password)) {
                return $this->redirector->to($this->returnUrl->pull());
            } else {
                $error = 'Invalid email or password.';
            }
        }

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'email' => $email,
            'error' => $error,
        ]);
    }
}

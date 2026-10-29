<?php

declare(strict_types=1);

namespace App\Web\Login;

use App\Service\LoginService;
use App\Service\TooManyAttemptsException;
use App\Shared\ClientIp;
use App\Web\Shared\Authentication\ReturnUrl;
use App\Web\Shared\Redirect\Redirector;
use App\Web\Shared\Throttling\TooManyAttempts;
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
            } else {
                try {
                    if ($this->loginService->login($email, $password, ClientIp::of($request))) {
                        return $this->redirector->to($this->returnUrl->pull());
                    }
                    $error = 'Invalid email or password.';
                } catch (TooManyAttemptsException $exception) {
                    return TooManyAttempts::respond($this->render($email, TooManyAttempts::message($exception)), $exception);
                }
            }
        }

        return $this->render($email, $error);
    }

    private function render(string $email, ?string $error): ResponseInterface
    {
        return $this->viewRenderer->render(__DIR__ . '/template', [
            'email' => $email,
            'error' => $error,
        ]);
    }
}

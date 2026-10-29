<?php

declare(strict_types=1);

namespace App\Web\Login;

use App\Service\LoginService;
use App\Web\Shared\Authentication\ReturnUrl;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private ResponseFactoryInterface $responseFactory,
        private UrlGeneratorInterface $urlGenerator,
        private CurrentUser $currentUser,
        private LoginService $loginService,
        private ReturnUrl $returnUrl,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->currentUser->isGuest()) {
            return $this->redirect($this->urlGenerator->generate('home'));
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
                return $this->redirect($this->returnUrl->pull());
            } else {
                $error = 'Invalid email or password.';
            }
        }

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'email' => $email,
            'error' => $error,
        ]);
    }

    private function redirect(string $url): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(Status::FOUND)
            ->withHeader('Location', $url);
    }
}

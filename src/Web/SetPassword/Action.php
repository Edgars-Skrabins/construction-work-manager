<?php

declare(strict_types=1);

namespace App\Web\SetPassword;

use App\Service\PasswordSetupService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\User\CurrentUser;
use Yiisoft\Validator\ValidatorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private ResponseFactoryInterface $responseFactory,
        private UrlGeneratorInterface $urlGenerator,
        private CurrentUser $currentUser,
        private ValidatorInterface $validator,
        private PasswordSetupService $passwordSetup,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->currentUser->isGuest()) {
            return $this->redirectHome();
        }

        $form = new SetPasswordForm();
        $errors = [];
        $error = null;

        if ($request->getMethod() === Method::POST) {
            $form = SetPasswordForm::fromBody($request->getParsedBody());
            $errors = $this->validator->validate($form)->getErrorMessagesIndexedByProperty();

            if ($errors === []) {
                if ($this->passwordSetup->setPassword($form->email, $form->setupCode, $form->password)) {
                    return $this->redirectHome();
                }
                $error = 'Invalid email or setup code.';
            }
        }

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'email' => $form->email,
            'errors' => $errors,
            'error' => $error,
        ]);
    }

    private function redirectHome(): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(Status::FOUND)
            ->withHeader('Location', $this->urlGenerator->generate('home'));
    }
}

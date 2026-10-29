<?php

declare(strict_types=1);

namespace App\Web\SetPassword;

use App\Service\PasswordSetupService;
use App\Web\Shared\Redirect\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Method;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\User\CurrentUser;
use Yiisoft\Validator\ValidatorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class Action
{
    public function __construct(
        private Redirector $redirector,
        private WebViewRenderer $viewRenderer,
        private CurrentUser $currentUser,
        private ValidatorInterface $validator,
        private PasswordSetupService $passwordSetup,
        private TranslatorInterface $translator,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->currentUser->isGuest()) {
            return $this->redirector->toRoute('home');
        }

        $form = new SetPasswordForm();
        $errors = [];
        $error = null;

        if ($request->getMethod() === Method::POST) {
            $form = SetPasswordForm::fromBody($request->getParsedBody());
            $errors = $this->validator->validate($form)->getErrorMessagesIndexedByProperty();

            if ($errors === []) {
                if ($this->passwordSetup->setPassword($form->email, $form->setupCode, $form->password)) {
                    return $this->redirector->toRoute('home');
                }
                $error = $this->translator->translate('Invalid email or setup code.');
            }
        }

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'email' => $form->email,
            'errors' => $errors,
            'error' => $error,
        ]);
    }
}

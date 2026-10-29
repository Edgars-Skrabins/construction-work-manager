<?php

declare(strict_types=1);

namespace App\Web\SiteForm;

use App\Service\ConstructionSiteService;
use App\Service\ValidationException;
use App\Web\NotFound\NotFoundHandler;
use App\Web\Shared\Flash\FlashMessages;
use App\Web\Shared\Redirect\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Method;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;
use Yiisoft\Validator\ValidatorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class Action
{
    public function __construct(
        private Redirector $redirector,
        private WebViewRenderer $viewRenderer,
        private CurrentRoute $currentRoute,
        private CurrentUser $currentUser,
        private ValidatorInterface $validator,
        private ConstructionSiteService $sites,
        private FlashMessages $flash,
        private NotFoundHandler $notFoundHandler,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $this->currentUser->getId();
        $id = $this->currentRoute->getArgument('id');
        $site = $id === null ? null : $this->sites->find($actorId, (int) $id);

        if ($id !== null && $site === null) {
            return $this->notFoundHandler->handle($request);
        }

        $form = $site === null ? new SiteForm() : SiteForm::fromSite($site);
        $errors = [];

        if ($request->getMethod() === Method::POST) {
            $form = SiteForm::fromBody($request->getParsedBody());
            $errors = $this->validator->validate($form)->getErrorMessagesIndexedByProperty();

            if ($errors === []) {
                try {
                    if ($site === null) {
                        $this->sites->create($actorId, $form->toData());
                    } else {
                        $this->sites->update($actorId, $site->id, $form->toData());
                    }
                    $this->flash->addSuccess('Construction site saved.');

                    return $this->redirector->toRoute('site.list');
                } catch (ValidationException $e) {
                    $errors = $e->mergeInto($errors);
                }
            }
        }

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'site' => $site,
            'form' => $form,
            'errors' => $errors,
            'accessLevels' => $this->sites->accessLevels($actorId),
        ]);
    }
}

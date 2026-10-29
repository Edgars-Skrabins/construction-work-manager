<?php

declare(strict_types=1);

namespace App\Web\SiteForm;

use App\Service\ValidationException;
use App\Service\ConstructionSiteService;
use App\Web\NotFound\NotFoundHandler;
use App\Web\Shared\Flash\FlashMessages;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;
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

                    return $this->responseFactory
                        ->createResponse(Status::FOUND)
                        ->withHeader('Location', $this->urlGenerator->generate('site.list'));
                } catch (ValidationException $e) {
                    foreach ($e->errors as $field => $message) {
                        $errors[$field][] = $message;
                    }
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

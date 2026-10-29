<?php

declare(strict_types=1);

namespace App\Web\WorkItemForm;

use App\Service\ValidationException;
use App\Service\WorkItemService;
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
        private WorkItemService $workItems,
        private FlashMessages $flash,
        private NotFoundHandler $notFoundHandler,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $this->currentUser->getId();
        $id = $this->currentRoute->getArgument('id');
        $workItem = $id === null ? null : $this->workItems->find($actorId, (int) $id);

        if ($id !== null && $workItem === null) {
            return $this->notFoundHandler->handle($request);
        }

        $form = $workItem === null ? new WorkItemForm() : WorkItemForm::fromWorkItem($workItem);
        $errors = [];

        if ($request->getMethod() === Method::POST) {
            $form = WorkItemForm::fromBody($request->getParsedBody());
            $errors = $this->validator->validate($form)->getErrorMessagesIndexedByProperty();

            if ($errors === []) {
                try {
                    if ($workItem === null) {
                        $this->workItems->create($actorId, $form->toData());
                    } else {
                        $this->workItems->update($actorId, $workItem->id, $form->toData());
                    }
                    $this->flash->addSuccess('Work item saved.');

                    return $this->responseFactory
                        ->createResponse(Status::FOUND)
                        ->withHeader('Location', $this->urlGenerator->generate('workItem.list'));
                } catch (ValidationException $e) {
                    foreach ($e->errors as $field => $message) {
                        $errors[$field][] = $message;
                    }
                }
            }
        }

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'workItem' => $workItem,
            'form' => $form,
            'errors' => $errors,
        ]);
    }
}

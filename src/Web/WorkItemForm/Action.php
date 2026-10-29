<?php

declare(strict_types=1);

namespace App\Web\WorkItemForm;

use App\Service\ValidationException;
use App\Service\WorkItemService;
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

                    return $this->redirector->toRoute('workItem.list');
                } catch (ValidationException $e) {
                    $errors = $e->mergeInto($errors);
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

<?php

declare(strict_types=1);

namespace App\Web\AssignmentForm;

use App\Entity\AssignmentStatus;
use App\Service\AssignmentService;
use App\Service\NotFoundException;
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
        private AssignmentService $assignments,
        private FlashMessages $flash,
        private NotFoundHandler $notFoundHandler,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $this->currentUser->getId();
        $id = $this->currentRoute->getArgument('id');
        $item = $id === null ? null : $this->assignments->find($actorId, (int) $id);

        if ($id !== null && $item === null) {
            return $this->notFoundHandler->handle($request);
        }

        $form = $item === null ? new AssignmentForm() : AssignmentForm::fromAssignment($item->assignment);
        $errors = [];

        if ($request->getMethod() === Method::POST) {
            $form = AssignmentForm::fromBody($request->getParsedBody(), $item !== null);
            $errors = $this->validator->validate($form)->getErrorMessagesIndexedByProperty();

            if ($errors === []) {
                try {
                    if ($item === null) {
                        $this->assignments->create(
                            $actorId,
                            (int) $form->employee,
                            (int) $form->workItem,
                            (int) $form->constructionSite,
                            $form->startDate,
                            $form->endDate,
                            $form->description,
                        );
                    } else {
                        $this->assignments->update($actorId, $item->assignment->id, (int) $form->employee, $form->startDate, $form->endDate, $form->status, $form->description, $form->expectedEmployeeId());
                    }
                    $this->flash->addSuccess('Assignment saved.');

                    return $this->redirector->toRoute('assignment.list');
                } catch (ValidationException $e) {
                    $errors = $e->mergeInto($errors);
                } catch (NotFoundException) {
                    return $this->notFoundHandler->handle($request);
                }
            }
        }

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'item' => $item,
            'form' => $form,
            'errors' => $errors,
            'options' => $item === null ? $this->assignments->formOptions($actorId) : $this->assignments->editOptions($actorId),
            'statuses' => AssignmentStatus::cases(),
        ]);
    }
}

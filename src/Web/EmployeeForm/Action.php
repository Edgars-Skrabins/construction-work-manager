<?php

declare(strict_types=1);

namespace App\Web\EmployeeForm;

use App\Service\EmployeeService;
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
        private EmployeeService $employees,
        private FlashMessages $flash,
        private NotFoundHandler $notFoundHandler,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $this->currentUser->getId();
        $id = $this->currentRoute->getArgument('id');
        $employee = $id === null ? null : $this->employees->find($actorId, (int) $id);

        if ($id !== null && $employee === null) {
            return $this->notFoundHandler->handle($request);
        }

        $form = $employee === null ? new EmployeeForm() : EmployeeForm::fromEmployee($employee);
        $errors = [];

        if ($request->getMethod() === Method::POST) {
            $form = EmployeeForm::fromBody($request->getParsedBody());
            $errors = $this->validator->validate($form)->getErrorMessagesIndexedByProperty();

            if ($errors === []) {
                try {
                    $saved = $employee === null
                        ? $this->employees->create($actorId, $form->toInput())
                        : $this->employees->update($actorId, $employee->id, $form->toInput());

                    if ($saved->setupCode !== null) {
                        $this->flash->setSetupCode($saved->setupCode);

                        return $this->redirector->toRoute('employee.setupCode', ['id' => $saved->id]);
                    }
                    $this->flash->addSuccess('Employee saved.');

                    return $this->redirector->toRoute('employee.list');
                } catch (ValidationException $e) {
                    $errors = $e->mergeInto($errors);
                }
            }
        }

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'employee' => $employee,
            'form' => $form,
            'errors' => $errors,
            'accessLevels' => $this->employees->accessLevels($actorId),
            'managers' => $this->employees->managers($actorId, $employee?->id),
            'roles' => ['admin', 'manager', 'employee'],
            'showAssignments' => $employee !== null && isset($errors['role']) && $this->employees->hasActiveAssignments($actorId, $employee->id),
        ]);
    }
}

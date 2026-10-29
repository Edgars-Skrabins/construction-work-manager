<?php

declare(strict_types=1);

namespace App\Web\EmployeeSetupCode;

use App\Service\EmployeeService;
use App\Service\ValidationException;
use App\Web\NotFound\NotFoundHandler;
use App\Web\Shared\Flash\FlashMessages;
use App\Web\Shared\Redirect\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Method;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class Action
{
    public function __construct(
        private Redirector $redirector,
        private WebViewRenderer $viewRenderer,
        private CurrentRoute $currentRoute,
        private CurrentUser $currentUser,
        private EmployeeService $employees,
        private FlashMessages $flash,
        private NotFoundHandler $notFoundHandler,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $this->currentUser->getId();
        $employee = $this->employees->find($actorId, (int) $this->currentRoute->getArgument('id'));

        if ($employee === null) {
            return $this->notFoundHandler->handle($request);
        }

        $pending = $employee->account !== null && $employee->account->passwordHash === null;

        if ($request->getMethod() === Method::POST) {
            try {
                $this->flash->setSetupCode($this->employees->newSetupCode($actorId, $employee->id));

                return $this->redirector->toRoute('employee.setupCode', ['id' => $employee->id]);
            } catch (ValidationException $e) {
                return $this->viewRenderer
                    ->render(__DIR__ . '/template', [
                        'employee' => $employee,
                        'setupCode' => null,
                        'pending' => $pending,
                        'error' => implode(' ', $e->errors),
                    ])
                    ->withStatus(Status::CONFLICT);
            }
        }

        return $this->viewRenderer
            ->render(__DIR__ . '/template', [
                'employee' => $employee,
                'setupCode' => $this->flash->setupCode(),
                'pending' => $pending,
                'error' => null,
            ])
            ->withHeader('Cache-Control', 'no-store');
    }
}

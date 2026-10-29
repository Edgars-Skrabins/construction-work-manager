<?php

declare(strict_types=1);

namespace App\Web\EmployeeDelete;

use App\Service\InUseException;
use App\Service\EmployeeService;
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
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private ResponseFactoryInterface $responseFactory,
        private UrlGeneratorInterface $urlGenerator,
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

        if ($request->getMethod() === Method::POST) {
            try {
                $this->employees->delete($actorId, $employee->id);
                $this->flash->addSuccess('Employee deleted.');

                return $this->responseFactory
                    ->createResponse(Status::FOUND)
                    ->withHeader('Location', $this->urlGenerator->generate('employee.list'));
            } catch (InUseException $e) {
                return $this->viewRenderer
                    ->render(__DIR__ . '/template', ['employee' => $employee, 'error' => $e->getMessage()])
                    ->withStatus(Status::CONFLICT);
            }
        }

        return $this->viewRenderer->render(__DIR__ . '/template', ['employee' => $employee, 'error' => null]);
    }
}

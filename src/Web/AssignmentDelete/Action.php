<?php

declare(strict_types=1);

namespace App\Web\AssignmentDelete;

use App\Service\AssignmentService;
use App\Service\NotFoundException;
use App\Web\NotFound\NotFoundHandler;
use App\Web\Shared\Flash\FlashMessages;
use App\Web\Shared\Redirect\Redirector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Method;
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
        private AssignmentService $assignments,
        private FlashMessages $flash,
        private NotFoundHandler $notFoundHandler,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $this->currentUser->getId();
        $id = (int) $this->currentRoute->getArgument('id');

        if ($request->getMethod() !== Method::POST) {
            $item = $this->assignments->find($actorId, $id);

            return $item === null
                ? $this->notFoundHandler->handle($request)
                : $this->viewRenderer->render(__DIR__ . '/template', ['item' => $item]);
        }

        try {
            $this->assignments->delete($actorId, $id);
        } catch (NotFoundException) {
            return $this->notFoundHandler->handle($request);
        }
        $this->flash->addSuccess('Assignment deleted.');

        return $this->redirector->toRoute('assignment.list');
    }
}

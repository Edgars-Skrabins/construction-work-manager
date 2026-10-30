<?php

declare(strict_types=1);

namespace App\Web\MyAssignmentDetails;

use App\Service\MyAssignmentsService;
use App\Service\NotFoundException;
use App\Web\NotFound\NotFoundHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private CurrentRoute $currentRoute,
        private CurrentUser $currentUser,
        private MyAssignmentsService $myAssignments,
        private NotFoundHandler $notFoundHandler,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $work = $this->myAssignments->find((int) $this->currentUser->getId(), (int) $this->currentRoute->getArgument('id'));
        } catch (NotFoundException) {
            return $this->notFoundHandler->handle($request);
        }

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'work' => $work,
            'status' => $this->myAssignments->displayStatus($work->item->assignment),
        ]);
    }
}

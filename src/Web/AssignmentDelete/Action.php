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
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

final readonly class Action
{
    public function __construct(
        private Redirector $redirector,
        private CurrentRoute $currentRoute,
        private CurrentUser $currentUser,
        private AssignmentService $assignments,
        private FlashMessages $flash,
        private NotFoundHandler $notFoundHandler,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->assignments->delete((int) $this->currentUser->getId(), (int) $this->currentRoute->getArgument('id'));
        } catch (NotFoundException) {
            return $this->notFoundHandler->handle($request);
        }
        $this->flash->addSuccess('Assignment deleted.');

        return $this->redirector->toRoute('assignment.list');
    }
}

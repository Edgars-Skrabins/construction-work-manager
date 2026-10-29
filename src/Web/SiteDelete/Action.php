<?php

declare(strict_types=1);

namespace App\Web\SiteDelete;

use App\Service\InUseException;
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
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private ResponseFactoryInterface $responseFactory,
        private UrlGeneratorInterface $urlGenerator,
        private CurrentRoute $currentRoute,
        private CurrentUser $currentUser,
        private ConstructionSiteService $sites,
        private FlashMessages $flash,
        private NotFoundHandler $notFoundHandler,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actorId = (int) $this->currentUser->getId();
        $site = $this->sites->find($actorId, (int) $this->currentRoute->getArgument('id'));

        if ($site === null) {
            return $this->notFoundHandler->handle($request);
        }

        if ($request->getMethod() === Method::POST) {
            try {
                $this->sites->delete($actorId, $site->id);
                $this->flash->addSuccess('Construction site deleted.');

                return $this->responseFactory
                    ->createResponse(Status::FOUND)
                    ->withHeader('Location', $this->urlGenerator->generate('site.list'));
            } catch (InUseException $e) {
                return $this->viewRenderer
                    ->render(__DIR__ . '/template', ['site' => $site, 'error' => $e->getMessage()])
                    ->withStatus(Status::CONFLICT);
            }
        }

        return $this->viewRenderer->render(__DIR__ . '/template', ['site' => $site, 'error' => null]);
    }
}

<?php

declare(strict_types=1);

namespace App\Web\SiteList;

use App\Service\ConstructionSiteService;
use App\Web\Shared\Flash\FlashMessages;
use App\Web\Shared\Listing\ListParams;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private CurrentUser $currentUser,
        private ConstructionSiteService $sites,
        private FlashMessages $flash,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = ListParams::fromRequest($request);
        $paginator = $params->applyTo($this->sites->listPaginator((int) $this->currentUser->getId(), $params->sort));

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'paginator' => $paginator,
            'messages' => $this->flash->success(),
        ]);
    }
}

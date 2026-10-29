<?php

declare(strict_types=1);

namespace App\Web\AssignmentList;

use App\Entity\AssignmentListItem;
use App\Service\AssignmentService;
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
        private AssignmentService $assignments,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = ListParams::fromRequest($request);
        $paginator = $params->applyTo($this->assignments->list((int) $this->currentUser->getId(), $params->sort, $params->q));
        $rows = array_map(
            fn(AssignmentListItem $item): array => ['item' => $item, 'status' => $this->assignments->displayStatus($item->assignment)],
            iterator_to_array($paginator->read(), false),
        );

        return $this->viewRenderer->render(__DIR__ . '/template', ['paginator' => $paginator, 'rows' => $rows, 'q' => $params->q]);
    }
}

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
        $params = ListParams::fromRequest($request, ['employee', 'workItem', 'site']);
        $paginator = $params->applyTo($this->assignments->list((int) $this->currentUser->getId(), $params->sort, $params->q, $params->filters));
        $rows = array_map(
            fn(AssignmentListItem $item): array => ['item' => $item, 'status' => $this->assignments->displayStatus($item->assignment)],
            iterator_to_array($paginator->read(), false),
        );

        return $this->viewRenderer->render(__DIR__ . '/template', [
            'paginator' => $paginator,
            'rows' => $rows,
            'q' => $params->q,
            'filters' => $params->filters,
            'chips' => self::chips($params->filters, $rows[0]['item'] ?? null),
        ]);
    }

    /**
     * @param array<string, int> $filters
     * @return list<array{filter: string, name: string, without: array<string, int>}>
     */
    private static function chips(array $filters, ?AssignmentListItem $first): array
    {
        $chips = [];
        foreach ($filters as $filter => $id) {
            $chips[] = [
                'filter' => $filter,
                'name' => match (true) {
                    $first === null => '#' . $id,
                    $filter === 'employee' => $first->employeeName,
                    $filter === 'workItem' => $first->workItemName,
                    default => $first->constructionSiteName,
                },
                'without' => array_diff_key($filters, [$filter => $id]),
            ];
        }

        return $chips;
    }
}

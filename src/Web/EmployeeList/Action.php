<?php

declare(strict_types=1);

namespace App\Web\EmployeeList;

use App\Service\EmployeeService;
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
        private EmployeeService $employees,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = ListParams::fromRequest($request);
        $paginator = $params->applyTo($this->employees->listPaginator((int) $this->currentUser->getId(), $params->sort));

        return $this->viewRenderer->render(__DIR__ . '/template', ['paginator' => $paginator]);
    }
}

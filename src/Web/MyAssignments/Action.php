<?php

declare(strict_types=1);

namespace App\Web\MyAssignments;

use App\Service\MyAssignmentsService;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private CurrentUser $currentUser,
        private MyAssignmentsService $myAssignments,
    ) {}

    public function __invoke(): ResponseInterface
    {
        return $this->viewRenderer->render(__DIR__ . '/template', [
            'myAssignments' => $this->myAssignments->forEmployee((int) $this->currentUser->getId()),
        ]);
    }
}

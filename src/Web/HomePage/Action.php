<?php

declare(strict_types=1);

namespace App\Web\HomePage;

use App\Access\Role;
use App\Repository\EmployeeRepositoryInterface;
use App\Web\Shared\Redirect\Redirector;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private CurrentUser $currentUser,
        private EmployeeRepositoryInterface $employees,
        private Redirector $redirector,
    ) {}

    public function __invoke(): ResponseInterface
    {
        if ($this->employees->roleOf((int) $this->currentUser->getId()) === Role::Employee) {
            return $this->redirector->toRoute('my-assignments');
        }

        return $this->viewRenderer->render(__DIR__ . '/template');
    }
}

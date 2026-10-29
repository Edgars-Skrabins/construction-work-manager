<?php

declare(strict_types=1);

namespace App\Web\HomePage;

use App\Access\Role;
use App\Repository\EmployeeRepositoryInterface;
use App\Web\Shared\Redirect\Redirector;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\User\CurrentUser;

final readonly class Action
{
    public function __construct(
        private CurrentUser $currentUser,
        private EmployeeRepositoryInterface $employees,
        private Redirector $redirector,
    ) {}

    public function __invoke(): ResponseInterface
    {
        return $this->redirector->toRoute(match ($this->employees->roleOf((int) $this->currentUser->getId())) {
            Role::Admin => 'employee.list',
            Role::Manager => 'assignment.list',
            default => 'my-assignments',
        });
    }
}

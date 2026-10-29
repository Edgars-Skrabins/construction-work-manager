<?php

declare(strict_types=1);

namespace App\Web\Shared\Access;

use App\Access\AccessChecker;
use App\Access\Permission;
use App\Service\EmployeeService;
use Yiisoft\User\CurrentUser;

final readonly class ViewAccess
{
    public function __construct(
        private CurrentUser $currentUser,
        private AccessChecker $accessChecker,
        private EmployeeService $employees,
    ) {}

    public function can(string $permission): bool
    {
        $permission = Permission::from($permission);
        $id = $this->currentUser->getId();
        if ($id === null) {
            return false;
        }

        return $this->accessChecker->can((int) $id, $permission);
    }

    public function canListEmployees(): bool
    {
        $id = $this->currentUser->getId();

        return $id !== null && $this->employees->canList((int) $id);
    }
}

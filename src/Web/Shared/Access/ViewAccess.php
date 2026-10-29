<?php

declare(strict_types=1);

namespace App\Web\Shared\Access;

use App\Access\AccessChecker;
use App\Access\Permission;
use Yiisoft\User\CurrentUser;

final class ViewAccess
{
    /** @var array<string, bool> */
    private array $checked = [];

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly AccessChecker $accessChecker,
    ) {}

    public function can(string $permission): bool
    {
        $permission = Permission::from($permission);
        $id = $this->currentUser->getId();
        if ($id === null) {
            return false;
        }

        return $this->checked[$id . ' ' . $permission->value] ??= $this->accessChecker->can((int) $id, $permission);
    }
}

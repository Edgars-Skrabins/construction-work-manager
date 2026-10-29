<?php

declare(strict_types=1);

namespace App\Access;

use Yiisoft\Rbac\ManagerInterface;

use function sprintf;

final readonly class AccessChecker
{
    public function __construct(
        private ManagerInterface $manager,
    ) {}

    public function can(int $employeeId, Permission $permission): bool
    {
        return $this->manager->userHasPermission((string) $employeeId, $permission->value);
    }

    public function ensure(int $employeeId, Permission $permission): void
    {
        if (!$this->can($employeeId, $permission)) {
            throw new ForbiddenException(sprintf('Employee %d lacks the %s permission.', $employeeId, $permission->value));
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Access;

final readonly class AccessScope
{
    public function forRole(Role $role, Entity $entity): Scope
    {
        return match ($role) {
            Role::Admin => Scope::All,
            Role::Manager => match ($entity) {
                Entity::Employee, Entity::Assignment => Scope::Subordinates,
                Entity::Site, Entity::WorkItem => Scope::All,
            },
            Role::Employee => Scope::Own,
        };
    }
}

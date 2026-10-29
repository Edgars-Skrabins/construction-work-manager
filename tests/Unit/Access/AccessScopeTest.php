<?php

declare(strict_types=1);

namespace App\Tests\Unit\Access;

use App\Access\AccessScope;
use App\Access\Entity;
use App\Access\Role;
use App\Access\Scope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

final class AccessScopeTest extends TestCase
{
    #[DataProvider('matrix')]
    public function testScopeMatchesTheMatrix(Role $role, Entity $entity, Scope $expected): void
    {
        assertSame($expected, (new AccessScope())->forRole($role, $entity));
    }

    /**
     * @return iterable<string, array{Role, Entity, Scope}>
     */
    public static function matrix(): iterable
    {
        yield 'admin employees' => [Role::Admin, Entity::Employee, Scope::All];
        yield 'admin sites' => [Role::Admin, Entity::Site, Scope::All];
        yield 'admin work items' => [Role::Admin, Entity::WorkItem, Scope::All];
        yield 'admin assignments' => [Role::Admin, Entity::Assignment, Scope::All];
        yield 'manager employees' => [Role::Manager, Entity::Employee, Scope::Subordinates];
        yield 'manager sites' => [Role::Manager, Entity::Site, Scope::All];
        yield 'manager work items' => [Role::Manager, Entity::WorkItem, Scope::All];
        yield 'manager assignments' => [Role::Manager, Entity::Assignment, Scope::Subordinates];
        yield 'employee employees' => [Role::Employee, Entity::Employee, Scope::Own];
        yield 'employee sites' => [Role::Employee, Entity::Site, Scope::Own];
        yield 'employee work items' => [Role::Employee, Entity::WorkItem, Scope::Own];
        yield 'employee assignments' => [Role::Employee, Entity::Assignment, Scope::Own];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Access;

use App\Access\AccessChecker;
use App\Access\ForbiddenException;
use App\Access\Permission;
use App\Tests\Integration\IntegrationTestCase;

final class AccessCheckerTest extends IntegrationTestCase
{
    public function testEnsureRefusesAnUnknownEmployee(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->service(AccessChecker::class)->ensure(9999, Permission::SiteRead);
    }
}

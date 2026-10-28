<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000003CreateConstructionSite extends SqlFileMigration
{
    protected function script(): string
    {
        return '003_create_construction_site';
    }
}

<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000017AddConstructionSiteCode extends SqlFileMigration
{
    protected function script(): string
    {
        return '017_add_construction_site_code';
    }
}

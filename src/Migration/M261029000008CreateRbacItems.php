<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000008CreateRbacItems extends SqlFileMigration
{
    protected function script(): string
    {
        return '008_create_rbac_items';
    }
}

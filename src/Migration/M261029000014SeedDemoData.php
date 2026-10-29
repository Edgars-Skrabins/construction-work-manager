<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000014SeedDemoData extends SqlFileMigration
{
    protected function script(): string
    {
        return '014_seed_demo_data';
    }
}

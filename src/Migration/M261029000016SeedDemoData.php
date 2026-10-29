<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000016SeedDemoData extends SqlFileMigration
{
    protected function script(): string
    {
        return '016_seed_demo_data';
    }
}

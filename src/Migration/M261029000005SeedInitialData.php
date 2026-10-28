<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000005SeedInitialData extends SqlFileMigration
{
    protected function script(): string
    {
        return '005_seed_initial_data';
    }
}

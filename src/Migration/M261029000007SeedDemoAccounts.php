<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000007SeedDemoAccounts extends SqlFileMigration
{
    protected function script(): string
    {
        return '007_seed_demo_accounts';
    }
}

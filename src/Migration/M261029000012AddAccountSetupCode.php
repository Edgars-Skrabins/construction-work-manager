<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000012AddAccountSetupCode extends SqlFileMigration
{
    protected function script(): string
    {
        return '012_add_account_setup_code';
    }
}

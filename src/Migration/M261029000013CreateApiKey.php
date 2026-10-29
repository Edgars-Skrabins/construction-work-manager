<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000013CreateApiKey extends SqlFileMigration
{
    protected function script(): string
    {
        return '013_create_api_key';
    }
}

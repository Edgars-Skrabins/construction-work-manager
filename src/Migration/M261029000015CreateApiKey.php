<?php

declare(strict_types=1);

namespace App\Migration;

final class M261029000015CreateApiKey extends SqlFileMigration
{
    protected function script(): string
    {
        return '015_create_api_key';
    }
}

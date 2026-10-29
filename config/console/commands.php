<?php

declare(strict_types=1);

use App\Console;

return [
    'hello' => Console\HelloCommand::class,
    'api-key:create' => Console\ApiKeyCreateCommand::class,
    'api-key:list' => Console\ApiKeyListCommand::class,
    'api-key:revoke' => Console\ApiKeyRevokeCommand::class,
];

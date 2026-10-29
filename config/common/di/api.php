<?php

declare(strict_types=1);

use App\Repository\ApiKeyRepository;
use App\Repository\ApiKeyRepositoryInterface;

return [
    ApiKeyRepositoryInterface::class => ApiKeyRepository::class,
];

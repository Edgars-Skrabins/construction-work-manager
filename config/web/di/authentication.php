<?php

declare(strict_types=1);

use App\Repository\AccountRepository;
use App\Repository\AccountRepositoryInterface;
use App\Repository\UserIdentityRepository;
use Yiisoft\Auth\IdentityRepositoryInterface;

return [
    AccountRepositoryInterface::class => AccountRepository::class,
    IdentityRepositoryInterface::class => UserIdentityRepository::class,
];

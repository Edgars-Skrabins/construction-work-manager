<?php

declare(strict_types=1);

use App\Repository\AccountRepository;
use App\Repository\AccountRepositoryInterface;
use App\Repository\UserIdentityRepository;
use App\Web\Shared\Authentication\WebAuthWithReturnUrl;
use Yiisoft\Auth\AuthenticationMethodInterface;
use Yiisoft\Auth\IdentityRepositoryInterface;
use Yiisoft\Definitions\Reference;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

/** @var array{'yiisoft/user': array{authTimeout: int}} $params */

return [
    AccountRepositoryInterface::class => AccountRepository::class,
    IdentityRepositoryInterface::class => UserIdentityRepository::class,
    AuthenticationMethodInterface::class => WebAuthWithReturnUrl::class,
    CurrentUser::class => [
        'withSession()' => [Reference::to(SessionInterface::class)],
        'withAuthTimeout()' => [$params['yiisoft/user']['authTimeout']],
    ],
];

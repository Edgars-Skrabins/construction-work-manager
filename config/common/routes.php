<?php

declare(strict_types=1);

use App\Web;
use Yiisoft\Auth\Middleware\Authentication;
use Yiisoft\Http\Method;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

return [
    Group::create()
        ->routes(
            Route::methods([Method::GET, Method::POST], '/login')
                ->action(Web\Login\Action::class)
                ->name('login'),
            Route::post('/logout')
                ->action(Web\Logout\Action::class)
                ->name('logout'),
            Group::create()
                ->middleware(Authentication::class)
                ->routes(
                    Route::get('/')
                        ->action(Web\HomePage\Action::class)
                        ->name('home'),
                ),
        ),
];

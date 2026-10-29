<?php

declare(strict_types=1);

use App\Access\Permission;
use App\Web;
use App\Web\Shared\Access\RequirePermission;
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
                    Route::get('/sites')
                        ->middleware(RequirePermission::for(Permission::SiteUpdate))
                        ->action(Web\SiteList\Action::class)
                        ->name('site.list'),
                    Route::methods([Method::GET, Method::POST], '/sites/create')
                        ->middleware(RequirePermission::for(Permission::SiteCreate))
                        ->action(Web\SiteForm\Action::class)
                        ->name('site.create'),
                    Route::methods([Method::GET, Method::POST], '/sites/{id:\d+}/edit')
                        ->middleware(RequirePermission::for(Permission::SiteUpdate))
                        ->action(Web\SiteForm\Action::class)
                        ->name('site.edit'),
                    Route::methods([Method::GET, Method::POST], '/sites/{id:\d+}/delete')
                        ->middleware(RequirePermission::for(Permission::SiteDelete))
                        ->action(Web\SiteDelete\Action::class)
                        ->name('site.delete'),
                    Route::get('/work-items')
                        ->middleware(RequirePermission::for(Permission::WorkItemUpdate))
                        ->action(Web\WorkItemList\Action::class)
                        ->name('workItem.list'),
                    Route::methods([Method::GET, Method::POST], '/work-items/create')
                        ->middleware(RequirePermission::for(Permission::WorkItemCreate))
                        ->action(Web\WorkItemForm\Action::class)
                        ->name('workItem.create'),
                    Route::methods([Method::GET, Method::POST], '/work-items/{id:\d+}/edit')
                        ->middleware(RequirePermission::for(Permission::WorkItemUpdate))
                        ->action(Web\WorkItemForm\Action::class)
                        ->name('workItem.edit'),
                    Route::methods([Method::GET, Method::POST], '/work-items/{id:\d+}/delete')
                        ->middleware(RequirePermission::for(Permission::WorkItemDelete))
                        ->action(Web\WorkItemDelete\Action::class)
                        ->name('workItem.delete'),
                ),
        ),
];

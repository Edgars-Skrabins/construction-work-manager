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
            Route::methods([Method::GET, Method::POST], '/set-password')
                ->action(Web\SetPassword\Action::class)
                ->name('setPassword'),
            Route::post('/logout')
                ->action(Web\Logout\Action::class)
                ->name('logout'),
            Group::create()
                ->middleware(Authentication::class)
                ->routes(
                    Route::get('/')
                        ->action(Web\HomePage\Action::class)
                        ->name('home'),
                    Route::get('/my-assignments')
                        ->middleware(RequirePermission::for(Permission::AssignmentRead))
                        ->action(Web\MyAssignments\Action::class)
                        ->name('my-assignments'),
                    Route::get('/employees')
                        ->middleware(RequirePermission::for(Permission::EmployeeUpdate))
                        ->action(Web\EmployeeList\Action::class)
                        ->name('employee.list'),
                    Route::methods([Method::GET, Method::POST], '/employees/create')
                        ->middleware(RequirePermission::for(Permission::EmployeeCreate))
                        ->action(Web\EmployeeForm\Action::class)
                        ->name('employee.create'),
                    Route::methods([Method::GET, Method::POST], '/employees/{id:\d+}/edit')
                        ->middleware(RequirePermission::for(Permission::EmployeeUpdate))
                        ->action(Web\EmployeeForm\Action::class)
                        ->name('employee.edit'),
                    Route::methods([Method::GET, Method::POST], '/employees/{id:\d+}/delete')
                        ->middleware(RequirePermission::for(Permission::EmployeeDelete))
                        ->action(Web\EmployeeDelete\Action::class)
                        ->name('employee.delete'),
                    Route::methods([Method::GET, Method::POST], '/employees/{id:\d+}/setup-code')
                        ->middleware(RequirePermission::for(Permission::EmployeeUpdate))
                        ->action(Web\EmployeeSetupCode\Action::class)
                        ->name('employee.setupCode'),
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
                    Route::get('/assignments')
                        ->middleware(RequirePermission::for(Permission::AssignmentUpdate))
                        ->action(Web\AssignmentList\Action::class)
                        ->name('assignment.list'),
                    Route::methods([Method::GET, Method::POST], '/assignments/create')
                        ->middleware(RequirePermission::for(Permission::AssignmentCreate))
                        ->action(Web\AssignmentForm\Action::class)
                        ->name('assignment.create'),
                    Route::methods([Method::GET, Method::POST], '/assignments/{id:\d+}/edit')
                        ->middleware(RequirePermission::for(Permission::AssignmentUpdate))
                        ->action(Web\AssignmentForm\Action::class)
                        ->name('assignment.edit'),
                    Route::post('/assignments/{id:\d+}/delete')
                        ->middleware(RequirePermission::for(Permission::AssignmentDelete))
                        ->action(Web\AssignmentDelete\Action::class)
                        ->name('assignment.delete'),
                ),
        ),
];

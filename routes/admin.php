<?php

use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\DictionaryItemController;
use App\Http\Controllers\Api\Admin\DictionaryTypeController;
use App\Http\Controllers\Api\Admin\MenuController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\SystemConfigController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\UserRoleController;
use Illuminate\Support\Facades\Route;

$adminPermission = static fn (string $permission): string => "permission:{$permission},admin";

Route::prefix('/admin')->name('admin.')->group(function () use ($adminPermission): void {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('auth.login');

    Route::middleware(['auth:admin', 'account.active:admin'])->group(function () use ($adminPermission): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::get('/menus/tree', [MenuController::class, 'tree'])->name('menus.tree');
        Route::apiResource('menus', MenuController::class)
            ->middlewareFor(['index', 'show'], $adminPermission('system.menu.view'))
            ->middlewareFor('store', $adminPermission('system.menu.create'))
            ->middlewareFor('update', $adminPermission('system.menu.update'))
            ->middlewareFor('destroy', $adminPermission('system.menu.delete'));

        Route::apiResource('roles', RoleController::class)
            ->middlewareFor(['index', 'show'], $adminPermission('system.role.view'))
            ->middlewareFor('store', $adminPermission('system.role.create'))
            ->middlewareFor('update', $adminPermission('system.role.update'))
            ->middlewareFor('destroy', $adminPermission('system.role.delete'));
        Route::put('/roles/{role}/permissions', [RoleController::class, 'syncPermissions'])
            ->middleware($adminPermission('system.role.update'))
            ->name('roles.permissions.update');

        Route::apiResource('permissions', PermissionController::class)
            ->middlewareFor(['index', 'show'], $adminPermission('system.permission.view'))
            ->middlewareFor('store', $adminPermission('system.permission.create'))
            ->middlewareFor('update', $adminPermission('system.permission.update'))
            ->middlewareFor('destroy', $adminPermission('system.permission.delete'));

        Route::apiResource('users', UserController::class)
            ->middlewareFor(['index', 'show'], $adminPermission('system.user.view'))
            ->middlewareFor('store', $adminPermission('system.user.create'))
            ->middlewareFor('update', $adminPermission('system.user.update'))
            ->middlewareFor('destroy', $adminPermission('system.user.delete'));
        Route::put('/users/{user}/roles', [UserRoleController::class, 'update'])
            ->middleware($adminPermission('system.user.assign-role'))
            ->name('users.roles.update');

        Route::apiResource('dictionary-types', DictionaryTypeController::class)
            ->middlewareFor(['index', 'show'], $adminPermission('system.dictionary.view'))
            ->middlewareFor('store', $adminPermission('system.dictionary.create'))
            ->middlewareFor('update', $adminPermission('system.dictionary.update'))
            ->middlewareFor('destroy', $adminPermission('system.dictionary.delete'))
            ->parameters(['dictionary-types' => 'dictionary_type']);

        Route::apiResource('dictionary-items', DictionaryItemController::class)
            ->middlewareFor(['index', 'show'], $adminPermission('system.dictionary.view'))
            ->middlewareFor('store', $adminPermission('system.dictionary.create'))
            ->middlewareFor('update', $adminPermission('system.dictionary.update'))
            ->middlewareFor('destroy', $adminPermission('system.dictionary.delete'))
            ->parameters(['dictionary-items' => 'dictionary_item']);

        Route::apiResource('system-configs', SystemConfigController::class)
            ->middlewareFor(['index', 'show'], $adminPermission('system.config.view'))
            ->middlewareFor('store', $adminPermission('system.config.create'))
            ->middlewareFor('update', $adminPermission('system.config.update'))
            ->middlewareFor('destroy', $adminPermission('system.config.delete'))
            ->parameters(['system-configs' => 'system_config']);
    });
});

<?php

use App\Http\Controllers\Api\Admin\ActivityLogController;
use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\DictionaryItemController;
use App\Http\Controllers\Api\Admin\DictionaryTypeController;
use App\Http\Controllers\Api\Admin\LoginLogController;
use App\Http\Controllers\Api\Admin\MediaController;
use App\Http\Controllers\Api\Admin\MemberController;
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
        ->middleware('throttle:admin-login')
        ->name('auth.login');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');

    Route::middleware(['auth:admin', 'jwt.version:admin', 'account.active:admin'])->group(function () use ($adminPermission): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::put('/auth/password', [AuthController::class, 'changePassword'])->name('auth.password.update');
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
        Route::put('/users/{user}/password', [UserController::class, 'resetPassword'])
            ->middleware($adminPermission('system.user.update'))
            ->name('users.password.update');

        Route::apiResource('members', MemberController::class)
            ->only(['index', 'store', 'show'])
            ->middlewareFor(['index', 'show'], $adminPermission('system.member.view'))
            ->middlewareFor('store', $adminPermission('system.member.create'));
        Route::put('/members/{member}', [MemberController::class, 'update'])
            ->middleware($adminPermission('system.member.update'))
            ->name('members.update');
        Route::put('/members/{member}/status', [MemberController::class, 'updateStatus'])
            ->middleware($adminPermission('system.member.status'))
            ->name('members.update-status');
        Route::put('/members/{member}/password', [MemberController::class, 'resetPassword'])
            ->middleware($adminPermission('system.member.reset_password'))
            ->name('members.reset-password');
        Route::post('/members/{member}/invalidate-sessions', [MemberController::class, 'invalidateSessions'])
            ->middleware($adminPermission('system.member.invalidate_sessions'))
            ->name('members.invalidate-sessions');

        Route::apiResource('media', MediaController::class)
            ->only(['index', 'store', 'destroy'])
            ->middlewareFor('index', $adminPermission('system.media.view'))
            ->middlewareFor('store', [
                $adminPermission('system.media.create'),
                'throttle:admin-media-upload',
            ])
            ->middlewareFor('destroy', $adminPermission('system.media.delete'))
            ->parameters(['media' => 'media']);

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

        Route::get('/activity-logs', [ActivityLogController::class, 'index'])
            ->middleware($adminPermission('system.activity-log.view'))
            ->name('activity-logs.index');
        Route::get('/login-logs', [LoginLogController::class, 'index'])
            ->middleware($adminPermission('system.login-log.view'))
            ->name('login-logs.index');
    });
});

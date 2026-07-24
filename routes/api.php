<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:member-api')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:member-login')
        ->name('member.auth.login');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->name('member.auth.refresh');

    Route::middleware(['auth:member', 'jwt.version:member', 'account.active:member'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('member.auth.me');
        Route::put('/auth/password', [AuthController::class, 'changePassword'])->name('member.auth.password.update');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('member.auth.logout');
    });
});

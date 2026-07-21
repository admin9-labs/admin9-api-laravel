<?php

use App\Http\Controllers\Api\Member\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:member-api')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:member-login')
        ->name('member.auth.login');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->name('member.auth.refresh');

    Route::middleware(['auth:member', 'account.active:member'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('member.auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('member.auth.logout');
    });
});

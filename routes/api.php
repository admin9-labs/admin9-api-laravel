<?php

use App\Http\Controllers\Api\Member\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:30,1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('member.auth.login');

    Route::middleware(['auth:member', 'account.active:member'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('member.auth.me');
        Route::post('/auth/refresh', [AuthController::class, 'refresh'])->name('member.auth.refresh');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('member.auth.logout');
    });
});

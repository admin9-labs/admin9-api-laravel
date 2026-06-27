<?php

use Illuminate\Support\Facades\Route;

Route::middleware('throttle:30,1')->group(function () {
    //
});

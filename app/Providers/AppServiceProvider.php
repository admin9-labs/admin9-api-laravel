<?php

namespace App\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Mitoop\Http\Exceptions\Handler;

class AppServiceProvider extends ServiceProvider
{
    public $singletons = [
        ExceptionHandler::class => Handler::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

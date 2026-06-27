<?php

namespace App\Providers;

use App\Http\Responses\ApiResponseGenerator;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Mitoop\Http\Exceptions\Handler;
use Mitoop\Http\ResponseGenerator;

class AppServiceProvider extends ServiceProvider
{
    public $singletons = [
        ExceptionHandler::class => Handler::class,
        ResponseGenerator::class => ApiResponseGenerator::class,
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

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth as JWTAuthFacade;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTFactory;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTProvider;
use PHPOpenSourceSaver\JWTAuth\JWT;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

class RefreshJwtGuards
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        Auth::forgetGuards();
        JWTAuthFacade::clearResolvedInstances();
        JWTFactory::clearResolvedInstances();
        JWTProvider::clearResolvedInstances();

        app(JWT::class)->setRequest($request)->unsetToken();
        app(JWTAuth::class)->setRequest($request)->unsetToken();

        return $next($request);
    }
}

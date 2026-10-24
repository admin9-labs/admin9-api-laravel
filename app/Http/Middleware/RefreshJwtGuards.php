<?php

namespace App\Http\Middleware;

use App\Support\ApiRouting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\JWT;
use Symfony\Component\HttpFoundation\Response;

class RefreshJwtGuards
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ApiRouting::matches($request)) {
            return $next($request);
        }

        Auth::forgetGuards();
        app(JWT::class)->setRequest($request)->unsetToken();

        return $next($request);
    }
}

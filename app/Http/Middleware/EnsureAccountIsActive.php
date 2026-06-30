<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mitoop\Http\JsonResponder;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $user = $request->user($guard);

        if ($user !== null && isset($user->is_active) && ! $user->is_active) {
            if ($guard !== null) {
                Auth::guard($guard)->logout();
            }

            return app(JsonResponder::class)->error('Account disabled', Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}

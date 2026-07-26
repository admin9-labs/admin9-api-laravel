<?php

namespace App\Http\Middleware;

use App\Support\Auth\AccountInactiveException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

            throw new AccountInactiveException;
        }

        return $next($request);
    }
}

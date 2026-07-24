<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Symfony\Component\HttpFoundation\Response;

class EnsureJwtAuthenticationVersion
{
    public function __construct(private AuthManager $auth) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $guard): Response
    {
        $account = $request->user($guard);
        $jwtGuard = $this->auth->guard($guard);

        if (! $account instanceof Model || ! $jwtGuard instanceof JWTGuard) {
            throw new AuthenticationException(guards: [$guard]);
        }

        try {
            $authenticationVersion = $jwtGuard->getPayload()->get('auth_version');
        } catch (JWTException) {
            throw new AuthenticationException(guards: [$guard]);
        }

        if (! is_int($authenticationVersion)
            || $authenticationVersion !== (int) $account->getAttribute('auth_version')) {
            throw new AuthenticationException(guards: [$guard]);
        }

        return $next($request);
    }
}

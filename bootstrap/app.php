<?php

use App\Http\Middleware\AddContext;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RefreshJwtGuards;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: [
            __DIR__.'/../routes/api.php',
            __DIR__.'/../routes/admin.php',
        ],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RefreshJwtGuards::class);
        $middleware->prepend(AddContext::class);
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (! $request->is('api/*') || ! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
                return $response;
            }

            $payload = json_decode((string) $response->getContent(), true);

            if (! is_array($payload) || ($payload['success'] ?? null) !== false) {
                return $response;
            }

            $status = $exception instanceof AuthenticationException
                ? 401
                : ($payload['code'] ?? null);

            if (! is_int($status) || ! in_array($status, [401, 403, 404, 413, 422], true)) {
                return $response;
            }

            $payload['code'] = $status;
            $response->setStatusCode($status);
            $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($encodedPayload !== false) {
                $response->setContent($encodedPayload);
            }

            return $response;
        });
    })->create();

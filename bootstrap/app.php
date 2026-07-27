<?php

use App\Exceptions\MediaDeleteFailedException;
use App\Http\Middleware\AddContext;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureJwtAuthenticationVersion;
use App\Http\Middleware\RefreshJwtGuards;
use App\Http\Responses\ApiResponseGenerator;
use App\Support\Auth\AccountInactiveException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api', 'api/*') ? null : route('login'),
        );
        $middleware->prepend(RefreshJwtGuards::class);
        $middleware->prepend(AddContext::class);
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'jwt.version' => EnsureJwtAuthenticationVersion::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api', 'api/*'),
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
                return $response;
            }

            $payload = json_decode((string) $response->getContent());

            if (! $payload instanceof stdClass || ($payload->success ?? null) !== false) {
                return $response;
            }

            if ($exception instanceof HttpExceptionInterface) {
                $response->headers->add($exception->getHeaders());
            }

            $status = match (true) {
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                $exception instanceof AuthenticationException => 401,
                default => $payload->code ?? null,
            };

            if (! ApiResponseGenerator::isHttpErrorCode($status)) {
                return $response;
            }

            $payload->code = $status;
            if ($request->is('api', 'api/*')) {
                $payload->data = new stdClass;
                $payload->errors = $status === 422 && ($payload->errors ?? null) instanceof stdClass
                    ? $payload->errors
                    : new stdClass;

                if ($exception instanceof AccountInactiveException) {
                    $payload->error_code = AccountInactiveException::ERROR_CODE;
                } elseif ($exception instanceof MediaDeleteFailedException) {
                    $payload->error_code = MediaDeleteFailedException::ERROR_CODE;
                }
            }

            $response->setStatusCode($status);
            $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($encodedPayload !== false) {
                $response->setContent($encodedPayload);
            }

            return $response;
        });
    })->create();

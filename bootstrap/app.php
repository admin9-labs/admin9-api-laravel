<?php

use App\Exceptions\ClientSafeException;
use App\Exceptions\FileDeleteFailedException;
use App\Exceptions\FileDirectoryNotEmptyException;
use App\Exceptions\ManagedSystemSettingException;
use App\Exceptions\MediaDeleteFailedException;
use App\Exceptions\MediaInUseBySystemSettingsException;
use App\Http\Middleware\AddContext;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureJwtAuthenticationVersion;
use App\Http\Middleware\EnsureMemberSession;
use App\Http\Middleware\RefreshJwtGuards;
use App\Support\ApiRouting;
use App\Support\Auth\AccountInactiveException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Mitoop\Http\Responder;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')
                ->domain(ApiRouting::domain())
                ->prefix(ApiRouting::prefix())
                ->group(base_path('routes/api.php'));

            $clientRoutes = array_filter(
                Route::getRoutes()->getRoutes(),
                static fn (Illuminate\Routing\Route $route): bool => in_array('api', $route->middleware(), true),
            );
            Route::middleware('api')
                ->domain(ApiRouting::adminDomain())
                ->prefix(ApiRouting::adminPrefix())
                ->group(base_path('routes/admin.php'));

            $adminRoutes = array_filter(
                Route::getRoutes()->getRoutes(),
                static fn (Illuminate\Routing\Route $route): bool => str_starts_with((string) $route->getName(), 'admin.'),
            );
            ApiRouting::assertNoRouteConflicts($clientRoutes, $adminRoutes);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => ApiRouting::matches($request) ? null : route('login'),
        );
        $middleware->prepend(RefreshJwtGuards::class);
        $middleware->prepend(AddContext::class);
        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'jwt.version' => EnsureJwtAuthenticationVersion::class,
            'member.session' => EnsureMemberSession::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ApiRouting::matches($request),
        );

        $exceptions->render(function (Throwable $exception): Response {
            $responder = app(Responder::class);

            if ($exception instanceof ClientSafeException) {
                return $responder->error(
                    $exception->getMessage(),
                    $exception->getErrorCode(),
                    status: $exception->getStatus(),
                );
            }

            if ($exception instanceof AuthenticationException) {
                return $responder->error(
                    'Unauthenticated',
                    code: Response::HTTP_UNAUTHORIZED,
                    status: Response::HTTP_UNAUTHORIZED,
                );
            }

            if ($exception instanceof ValidationException) {
                return $responder->error(
                    Arr::first(Arr::flatten($exception->errors())),
                    code: $exception->status,
                    errors: $exception->errors(),
                    status: $exception->status,
                );
            }

            if ($exception instanceof HttpExceptionInterface) {
                return $responder->error(
                    $exception->getMessage(),
                    code: $exception->getStatusCode(),
                    status: $exception->getStatusCode(),
                );
            }

            $errors = new stdClass;
            if (config('app.debug')) {
                $errors = [
                    'class' => get_class($exception),
                    'code' => $exception->getCode(),
                    'message' => $exception->getMessage(),
                    'file' => sprintf('%s:%d', $exception->getFile(), $exception->getLine()),
                ];
            }

            return $responder->error(
                'Something went wrong',
                code: Response::HTTP_INTERNAL_SERVER_ERROR,
                errors: $errors,
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        });

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
                default => null,
            };

            if (! is_int($status) || $status < 400 || $status > 599) {
                return $response;
            }

            $payload->code = $status;
            if (ApiRouting::matches($request)) {
                $payload->data = new stdClass;
                $payload->errors = $status === 422 && ($payload->errors ?? null) instanceof stdClass
                    ? $payload->errors
                    : new stdClass;

                if ($exception instanceof AccountInactiveException) {
                    $payload->error_code = AccountInactiveException::ERROR_CODE;
                } elseif ($exception instanceof MediaDeleteFailedException) {
                    $payload->error_code = MediaDeleteFailedException::ERROR_CODE;
                } elseif ($exception instanceof FileDirectoryNotEmptyException) {
                    $payload->error_code = FileDirectoryNotEmptyException::ERROR_CODE;
                } elseif ($exception instanceof FileDeleteFailedException) {
                    $payload->error_code = FileDeleteFailedException::ERROR_CODE;
                } elseif ($exception instanceof MediaInUseBySystemSettingsException) {
                    $payload->error_code = MediaInUseBySystemSettingsException::ERROR_CODE;
                } elseif ($exception instanceof ManagedSystemSettingException) {
                    $payload->error_code = ManagedSystemSettingException::ERROR_CODE;
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

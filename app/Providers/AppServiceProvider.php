<?php

namespace App\Providers;

use App\Http\Responses\ApiResponseGenerator;
use App\Models\Permission;
use App\Models\User;
use App\Support\Admin\ReservedAdminRole;
use App\Support\AdminApiOpenApiContract;
use App\Support\ApiErrorOpenApiContract;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Mitoop\Http\Exceptions\Handler;
use Mitoop\Http\JsonResponderDefault;
use Mitoop\Http\ResponseGenerator;
use Symfony\Component\HttpFoundation\Response;

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
        app(JsonResponderDefault::class)->apply([
            'deny' => Response::HTTP_FORBIDDEN,
        ]);

        RateLimiter::for('member-api', static function (Request $request): Limit {
            $memberGuard = Auth::guard('member');
            $member = $memberGuard->hasUser() ? $memberGuard->user() : null;
            $key = $member === null
                ? 'ip:'.$request->ip()
                : 'member:'.$member->getAuthIdentifier();

            return Limit::perMinute(30)->by($key);
        });
        RateLimiter::for('member-login', static fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('admin-login', static fn (Request $request): Limit => Limit::perMinute(5)->by('admin:login:ip:'.$request->ip()));
        RateLimiter::for('admin-media-upload', static function (Request $request): Limit {
            $admin = $request->user('admin');
            $key = $admin === null
                ? 'admin:media-upload:ip:'.$request->ip()
                : 'admin:media-upload:user:'.$admin->getAuthIdentifier();

            return Limit::perMinute(10)->by($key);
        });

        if (class_exists(Scramble::class)) {
            $openApiContract = app(AdminApiOpenApiContract::class);
            $apiErrorOpenApiContract = app(ApiErrorOpenApiContract::class);

            Scramble::configure()->withOperationTransformers(
                static function (Operation $operation, RouteInfo $routeInfo) use ($openApiContract): void {
                    $routeName = $routeInfo->route->getName();

                    $openApiContract->transformOperation($operation, $routeInfo);

                    if (in_array($routeName, ['member.auth.refresh', 'admin.auth.refresh'], true)) {
                        $operation->addSecurity(new SecurityRequirement(['http' => []]));
                    }

                },
            )->withDocumentTransformers(
                static function (OpenApi $document) use ($openApiContract, $apiErrorOpenApiContract): void {
                    $openApiContract->transformDocument($document);
                    $apiErrorOpenApiContract->transformDocument($document);
                },
            );
        }

        Gate::before(function (User $user, string $ability): ?bool {
            $permission = Permission::query()
                ->where('name', $ability)
                ->where('guard_name', 'admin')
                ->first(['is_active']);

            if ($permission !== null && ! (bool) $permission->is_active) {
                return false;
            }

            if ($permission === null) {
                return null;
            }

            if (ReservedAdminRole::userIsSuperAdmin($user)) {
                return true;
            }

            return $user->checkPermissionTo($ability, 'admin') ? true : null;
        });
    }
}

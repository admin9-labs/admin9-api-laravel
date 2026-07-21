<?php

namespace App\Providers;

use App\Http\Responses\ApiResponseGenerator;
use App\Models\Permission;
use App\Models\User;
use App\Support\Admin\ReservedAdminRole;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
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

        RateLimiter::for('member-api', static fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('member-login', static fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));

        if (class_exists(Scramble::class)) {
            Scramble::configure()->withOperationTransformers(
                static function (Operation $operation, RouteInfo $routeInfo): void {
                    if (! in_array($routeInfo->route->getName(), ['member.auth.refresh', 'admin.auth.refresh'], true)) {
                        return;
                    }

                    $operation->addSecurity(new SecurityRequirement(['http' => []]));
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

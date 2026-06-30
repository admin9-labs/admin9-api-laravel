<?php

namespace App\Providers;

use App\Http\Responses\ApiResponseGenerator;
use App\Models\Permission;
use App\Models\User;
use App\Support\Admin\ReservedAdminRole;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Gate;
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

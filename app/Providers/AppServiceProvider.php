<?php

namespace App\Providers;

use App\Http\Responses\ApiResponseGenerator;
use App\Models\Permission;
use App\Models\User;
use App\Support\Admin\ReservedAdminRole;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response as OpenApiResponse;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\ArrayType as OpenApiArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType as OpenApiBooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType as OpenApiIntegerType;
use Dedoc\Scramble\Support\Generator\Types\MixedType as OpenApiMixedType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType as OpenApiStringType;
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

        if (class_exists(Scramble::class)) {
            Scramble::configure()->withOperationTransformers(
                static function (Operation $operation, RouteInfo $routeInfo): void {
                    $routeName = $routeInfo->route->getName();

                    if (in_array($routeName, ['member.auth.refresh', 'admin.auth.refresh'], true)) {
                        $operation->addSecurity(new SecurityRequirement(['http' => []]));
                    }

                    if (in_array($routeName, ['member.auth.login', 'admin.auth.login'], true)) {
                        $operation->addResponse(self::openApiRateLimitResponse());
                    }
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

    private static function openApiRateLimitResponse(): OpenApiResponse
    {
        $emptyArray = static fn (): OpenApiArrayType => (new OpenApiArrayType)
            ->setItems(new OpenApiMixedType)
            ->setMax(0);

        $envelope = (new OpenApiObjectType)
            ->addProperty('success', new OpenApiBooleanType)
            ->addProperty('code', new OpenApiIntegerType)
            ->addProperty('message', new OpenApiStringType)
            ->addProperty('data', $emptyArray())
            ->addProperty('errors', $emptyArray())
            ->addProperty('request_id', new OpenApiStringType)
            ->setRequired(['success', 'code', 'message', 'data', 'errors', 'request_id']);

        $integerHeader = static fn (string $description): Header => new Header(
            description: $description,
            schema: Schema::fromType(new OpenApiIntegerType),
        );

        return OpenApiResponse::make(Response::HTTP_TOO_MANY_REQUESTS)
            ->setDescription('Too Many Requests')
            ->setContent('application/json', Schema::fromType($envelope))
            ->setHeaders([
                'Retry-After' => $integerHeader('Seconds until the client may retry.'),
                'X-RateLimit-Limit' => $integerHeader('Maximum requests allowed in the current window.'),
                'X-RateLimit-Remaining' => $integerHeader('Requests remaining in the current window.'),
                'X-RateLimit-Reset' => $integerHeader('Unix timestamp when the current window resets.'),
            ]);
    }
}

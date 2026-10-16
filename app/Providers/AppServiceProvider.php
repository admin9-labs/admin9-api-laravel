<?php

namespace App\Providers;

use App\Http\Responses\ApiResponseGenerator;
use App\Models\Permission;
use App\Models\User;
use App\Support\Admin\ReservedAdminRole;
use App\Support\AdminApiOpenApiContract;
use App\Support\ApiErrorOpenApiContract;
use App\Support\Database\TestingDatabaseGuard;
use App\Support\OpenApi\ApiDocumentRouting;
use App\Support\OpenApi\DocumentPruner;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
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
        TestingDatabaseGuard::ensureIsolated($this->app);

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
        RateLimiter::for(
            'public-system-settings',
            static fn (Request $request): Limit => Limit::perMinute(60)->by('public:system-settings:ip:'.$request->ip()),
        );
        RateLimiter::for('admin-file-upload', static function (Request $request): Limit {
            $admin = $request->user('admin');
            $key = $admin === null
                ? 'admin:file-upload:ip:'.$request->ip()
                : 'admin:file-upload:user:'.$admin->getAuthIdentifier();

            return Limit::perMinute(10)->by($key);
        });

        if (class_exists(Scramble::class)) {
            $openApiContract = app(AdminApiOpenApiContract::class);
            $apiErrorOpenApiContract = app(ApiErrorOpenApiContract::class);
            $documentRouting = app(ApiDocumentRouting::class);
            $documentPruner = app(DocumentPruner::class);

            Scramble::configure()->withOperationTransformers(
                static function (Operation $operation, RouteInfo $routeInfo) use ($openApiContract, $documentRouting): void {
                    $routeName = $routeInfo->route->getName();

                    $documentRouting->assertCanonicalDestination($routeInfo->route);
                    $operation->servers([]);
                    $openApiContract->transformOperation($operation, $routeInfo);

                    if (in_array($routeName, ['member.auth.refresh', 'admin.auth.refresh'], true)) {
                        $operation->addSecurity(new SecurityRequirement(['http' => []]));
                    }
                },
            );

            $adminDocumentation = Scramble::registerApi('admin');

            foreach (['client' => Scramble::configure(), 'admin' => $adminDocumentation] as $audience => $documentation) {
                $admin = $audience === 'admin';
                $documentation->useConfig(array_replace_recursive(config('scramble'), [
                    'export_path' => 'docs/'.$audience.'-api.json',
                    'servers' => [$documentRouting->origin($admin)],
                    'ui' => ['title' => $admin ? 'Admin9 Admin API' : 'Admin9 Client API'],
                ]))->routes(static fn (LaravelRoute $route): bool => in_array('api', $route->gatherMiddleware(), true)
                    && ($route->getName() === 'system-settings.public'
                        || str_starts_with($route->getName() ?? '', 'admin.') === $admin))
                    ->expose(ui: '/docs/'.$audience.'-api', document: '/docs/'.$audience.'-api.json')
                    ->withDocumentTransformers(
                        static function (OpenApi $document) use ($admin, $openApiContract, $apiErrorOpenApiContract, $documentRouting, $documentPruner): void {
                            $openApiContract->transformDocument($document, $admin);
                            $apiErrorOpenApiContract->transformDocument($document);
                            $openApiContract->addPatchAliases($document);
                            $documentRouting->describeSharedSettings($document);
                            $documentPruner->prune($document);
                        },
                    );
            }
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

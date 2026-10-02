<?php

namespace Tests\Feature;

use App\Models\DictionaryItem;
use App\Models\DictionaryType;
use App\Models\LoginLog;
use App\Models\SystemConfig;
use App\Models\User;
use App\Support\ApiRouting;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminQueryGrowthTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Log::setDefaultDriver('null');
    }

    public function test_user_list_queries_do_not_grow_with_distinct_role_assignments(): void
    {
        $token = $this->managerTokenFor(['system.user.view']);

        foreach (range(1, 50) as $index) {
            User::factory()->create()->assignRole($this->roleWithPermission($index));
        }

        $this->assertPaginatedQueryGrowthBounded('/admin/users', $token, static function (TestResponse $response): void {
            $response->assertJsonCount(1, 'data.0.roles');
        });
    }

    public function test_role_list_queries_do_not_grow_with_distinct_permissions(): void
    {
        $token = $this->managerTokenFor(['system.role.view']);
        $counts = [];
        $created = 0;

        foreach ([1, 10, 50] as $size) {
            while ($created < $size) {
                $this->roleWithPermission(++$created);
            }

            $counts[] = $this->measureSelects('/admin/roles', $token, static function (TestResponse $response) use ($size): void {
                $response->assertJsonCount($size, 'data')->assertJsonCount(1, 'data.0.permissions');
            });
        }

        $this->assertQueryGrowthBounded('/admin/roles', $counts);
    }

    public function test_dictionary_item_list_queries_do_not_grow_with_distinct_types(): void
    {
        $token = $this->managerTokenFor(['system.dictionary.view']);
        DictionaryItem::factory()->count(50)->create();

        $this->assertPaginatedQueryGrowthBounded('/admin/dictionary-items', $token, function (TestResponse $response): void {
            foreach ($response->json('data') as $item) {
                $this->assertSame($item['dictionary_type_id'], $item['type']['id']);
            }
        });
    }

    public function test_dictionary_type_list_queries_do_not_grow_with_item_counts(): void
    {
        $token = $this->managerTokenFor(['system.dictionary.view']);
        DictionaryType::factory()->count(50)->has(DictionaryItem::factory()->count(2), 'items')->create();

        $this->assertPaginatedQueryGrowthBounded('/admin/dictionary-types', $token, function (TestResponse $response): void {
            foreach ($response->json('data') as $type) {
                $this->assertSame(2, $type['items_count']);
            }
        });
    }

    public function test_system_config_list_queries_do_not_grow_with_page_size(): void
    {
        $token = $this->managerTokenFor(['system.config.view']);
        SystemConfig::factory()->count(50)->create();

        $this->assertPaginatedQueryGrowthBounded('/admin/system-configs', $token);
    }

    public function test_activity_list_queries_do_not_grow_with_distinct_subjects_and_causers(): void
    {
        $token = $this->managerTokenFor(['system.activity-log.view']);

        foreach (User::factory()->count(50)->create() as $subject) {
            Activity::query()->create([
                'log_name' => 'query-growth',
                'event' => 'updated',
                'description' => 'Query growth fixture',
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->id,
                'causer_type' => $subject->getMorphClass(),
                'causer_id' => $subject->id,
                'properties' => ['attributes' => ['name' => $subject->name]],
            ]);
        }

        $this->assertPaginatedQueryGrowthBounded('/admin/activity-logs?log_name=query-growth', $token);
    }

    public function test_login_log_list_queries_do_not_grow_with_distinct_subjects(): void
    {
        $token = $this->managerTokenFor(['system.login-log.view']);

        foreach (User::factory()->count(50)->create() as $subject) {
            LoginLog::query()->create([
                'guard' => 'admin',
                'account' => $subject->email,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->id,
                'event' => 'query-growth',
                'successful' => true,
                'context' => ['route' => 'admin.auth.login'],
            ]);
        }

        $this->assertPaginatedQueryGrowthBounded('/admin/login-logs?event=query-growth', $token);
    }

    public function test_admin_identity_queries_do_not_grow_with_distinct_roles_and_permissions(): void
    {
        $user = User::factory()->create();
        $token = $this->adminTokenFor($user);
        $counts = [];
        $created = 0;

        foreach ([1, 10, 50] as $size) {
            while ($created < $size) {
                $user->assignRole($this->roleWithPermission(++$created));
            }

            $counts[] = $this->measureSelects('/admin/auth/me', $token, static function (TestResponse $response) use ($size): void {
                $response->assertJsonCount($size, 'data.user.roles')
                    ->assertJsonCount($size, 'data.permission_names');
            });
        }

        $this->assertQueryGrowthBounded('/admin/auth/me', $counts);
    }

    private function roleWithPermission(int $index): Role
    {
        $role = Role::query()->create(['name' => 'query-growth-'.$index, 'guard_name' => 'admin']);
        $role->givePermissionTo($this->createPermission('query-growth.permission-'.$index));

        return $role;
    }

    /**
     * @param  (Closure(TestResponse): void)|null  $assertResponse
     */
    private function assertPaginatedQueryGrowthBounded(string $path, string $token, ?Closure $assertResponse = null): void
    {
        $counts = [];

        foreach ([1, 10, 50] as $size) {
            $url = $path.(str_contains($path, '?') ? '&' : '?').'page_size='.$size;
            $counts[] = $this->measureSelects($url, $token, static function (TestResponse $response) use ($assertResponse, $size): void {
                $response->assertJsonCount($size, 'data');
                $assertResponse?->__invoke($response);
            });
        }

        $this->assertQueryGrowthBounded($path, $counts);
    }

    /**
     * @param  Closure(TestResponse): void  $assertResponse
     */
    private function measureSelects(string $path, string $token, Closure $assertResponse): int
    {
        Auth::forgetGuards();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->getJson(ApiRouting::path($path), ['Authorization' => 'Bearer '.$token])->assertOk();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $assertResponse($response);

        return count(array_filter($queries, static fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select')));
    }

    /**
     * @param  list<int>  $counts
     */
    private function assertQueryGrowthBounded(string $path, array $counts): void
    {
        $this->assertGreaterThan(0, $counts[0]);

        foreach (array_slice($counts, 1) as $count) {
            $this->assertLessThanOrEqual($counts[0] + 2, $count, $path.' must not add one SELECT per returned record or relation.');
        }
    }
}

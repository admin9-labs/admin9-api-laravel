<?php

namespace Tests\Feature;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminAuditLogApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_activity_and_login_logs_require_independent_view_permissions(): void
    {
        $this->createPermission('system.activity-log.view');
        $this->createPermission('system.login-log.view');

        $activityViewer = User::factory()->create(['email' => 'activity-log-viewer@example.com']);
        $activityViewer->givePermissionTo('system.activity-log.view');
        $activityToken = $this->adminTokenFor($activityViewer);

        $loginViewer = User::factory()->create(['email' => 'login-log-viewer@example.com']);
        $loginViewer->givePermissionTo('system.login-log.view');
        $loginToken = $this->adminTokenFor($loginViewer);

        $this->getJson('/api/admin/activity-logs', ['Authorization' => 'Bearer '.$activityToken])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/admin/login-logs', ['Authorization' => 'Bearer '.$activityToken])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403)
            ->assertJsonPath('data', [])
            ->assertJsonPath('errors', []);

        $this->getJson('/api/admin/login-logs', ['Authorization' => 'Bearer '.$loginToken])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/admin/activity-logs', ['Authorization' => 'Bearer '.$loginToken])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 403);
    }

    public function test_activity_logs_use_standard_pagination_filters_and_sanitize_properties(): void
    {
        $this->createPermission('system.activity-log.view');
        $viewer = User::factory()->create(['email' => 'activity-filter-viewer@example.com']);
        $viewer->givePermissionTo('system.activity-log.view');
        $token = $this->adminTokenFor($viewer);
        $subject = User::factory()->create(['email' => 'activity-filter-subject@example.com']);

        $this->createActivity($subject, $viewer, 'updated', '2026-07-23 10:00:00', [
            'attributes' => ['name' => 'first match'],
        ]);
        $this->createActivity($subject, $viewer, 'updated', '2026-07-24 10:00:00', [
            'attributes' => [
                'name' => 'latest match',
                'password' => 'activity-plain-secret',
                'nested' => ['authorization' => 'Bearer activity-secret-token'],
            ],
        ]);
        $this->createActivity($subject, $viewer, 'created', '2026-07-24 11:00:00', [
            'attributes' => ['name' => 'not matched'],
        ]);

        $query = http_build_query([
            'page_size' => 1,
            'log_name' => 'admin',
            'event' => 'updated',
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->id,
            'causer_id' => $viewer->id,
            'created_at' => ['2026-07-23', '2026-07-24'],
        ]);

        $response = $this->getJson('/api/admin/activity-logs?'.$query, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination', 'page')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.page_size', 1)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'updated')
            ->assertJsonPath('data.0.properties.attributes.name', 'latest match')
            ->assertHeader('X-Request-Id');

        $properties = $response->json('data.0.properties');
        $this->assertIsArray($properties);
        $this->assertPayloadHasNoSensitiveKeys($properties);
        $this->assertStringNotContainsString('activity-plain-secret', json_encode($properties, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('activity-secret-token', json_encode($properties, JSON_THROW_ON_ERROR));
    }

    public function test_login_logs_use_standard_pagination_filters_and_sanitize_context(): void
    {
        $this->createPermission('system.login-log.view');
        $viewer = User::factory()->create(['email' => 'login-filter-viewer@example.com']);
        $viewer->givePermissionTo('system.login-log.view');
        $token = $this->adminTokenFor($viewer);
        $subject = User::factory()->create(['email' => 'login-filter-subject@example.com']);

        $this->createLoginLog($subject, 'filter-account-first@example.com', '2026-07-23 10:00:00');
        $this->createLoginLog($subject, 'filter-account-latest@example.com', '2026-07-24 10:00:00', [
            'route' => 'admin.auth.login',
            'password_hash' => '$2y$12$login-secret-hash',
            'nested' => ['access_token' => 'login-secret-token'],
        ]);
        LoginLog::query()->create([
            'guard' => 'member',
            'account' => 'filter-account-member@example.com',
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->id,
            'event' => 'login',
            'successful' => false,
            'failure_reason' => 'Invalid credentials',
            'ip_address' => '127.0.0.1',
            'created_at' => '2026-07-24 11:00:00',
            'updated_at' => '2026-07-24 11:00:00',
        ]);

        $query = http_build_query([
            'page_size' => 1,
            'guard' => 'admin',
            'event' => 'login',
            'successful' => 0,
            'account' => 'account',
            'subject_id' => $subject->id,
            'ip_address' => '127.0.0.1',
            'created_at' => ['2026-07-23', '2026-07-24'],
        ]);

        $response = $this->getJson('/api/admin/login-logs?'.$query, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination', 'page')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.page_size', 1)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.guard', 'admin')
            ->assertJsonPath('data.0.account', 'filter-account-latest@example.com')
            ->assertJsonPath('data.0.successful', false)
            ->assertJsonPath('data.0.context.route', 'admin.auth.login')
            ->assertHeader('X-Request-Id');

        $context = $response->json('data.0.context');
        $this->assertIsArray($context);
        $this->assertPayloadHasNoSensitiveKeys($context);
        $this->assertStringNotContainsString('login-secret-hash', json_encode($context, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('login-secret-token', json_encode($context, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function createActivity(User $subject, User $causer, string $event, string $createdAt, array $properties): Activity
    {
        /** @var Activity $activity */
        $activity = activity('admin')
            ->event($event)
            ->performedOn($subject)
            ->causedBy($causer)
            ->withProperties($properties)
            ->log('User '.$event);

        $activity->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $activity;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createLoginLog(User $subject, string $account, string $createdAt, array $context = []): LoginLog
    {
        $loginLog = LoginLog::query()->create([
            'guard' => 'admin',
            'account' => $account,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->id,
            'event' => 'login',
            'successful' => false,
            'failure_reason' => 'Invalid credentials',
            'ip_address' => '127.0.0.1',
            'context' => $context,
        ]);

        $loginLog->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $loginLog;
    }

    private function createPermission(string $permissionName): Permission
    {
        $permission = Permission::findOrCreate($permissionName, 'admin');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $permission;
    }

    private function adminTokenFor(User $user): string
    {
        $response = $this->postJson('/api/admin/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $token = $response->json('data.access_token');
        $this->assertIsString($token);

        return $token;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function assertPayloadHasNoSensitiveKeys(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $this->assertDoesNotMatchRegularExpression(
                    '/password|token|secret|jwt|authorization|api[\s._-]*key/i',
                    $key,
                );
            }

            if (is_array($value)) {
                $this->assertPayloadHasNoSensitiveKeys($value);
            }
        }
    }
}

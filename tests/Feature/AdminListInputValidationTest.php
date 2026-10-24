<?php

namespace Tests\Feature;

use App\Models\SystemConfig;
use App\Support\ApiRouting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminListInputValidationTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    #[DataProvider('invalidFilters')]
    public function test_invalid_query_values_return_validation_errors(string $path, string $permission, string $field, mixed $value): void
    {
        $token = $this->managerTokenFor([$permission]);

        $response = $this->getJson(ApiRouting::path('/admin/'.$path).'?'.http_build_query([$field => $value]), [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 422);

        $this->assertNotEmpty(array_filter(
            array_keys($response->json('errors')),
            static fn (string $key): bool => $key === $field || str_starts_with($key, $field.'.'),
        ));
    }

    /**
     * @return array<string, array{string, string, string, mixed}>
     */
    public static function invalidFilters(): array
    {
        return [
            'dictionary type name array' => ['dictionary-types', 'system.dictionary.view', 'name', ['bad']],
            'dictionary type keyword array' => ['dictionary-types', 'system.dictionary.view', 'keyword', ['bad']],
            'dictionary item value array' => ['dictionary-items', 'system.dictionary.view', 'value', ['bad']],
            'dictionary item identifier array' => ['dictionary-items', 'system.dictionary.view', 'dictionary_type_id', ['bad']],
            'configuration keyword array' => ['system-configs', 'system.config.view', 'keyword', ['bad']],
            'configuration type array' => ['system-configs', 'system.config.view', 'type', ['bad']],
            'configuration key over schema limit' => ['system-configs', 'system.config.view', 'key', str_repeat('a', 151)],
            'login account array' => ['login-logs', 'system.login-log.view', 'account', ['bad']],
            'login account too long' => ['login-logs', 'system.login-log.view', 'account', str_repeat('a', 256)],
            'login subject zero' => ['login-logs', 'system.login-log.view', 'subject_id', 0],
            'login invalid boolean' => ['login-logs', 'system.login-log.view', 'successful', 'yes'],
            'activity subject array' => ['activity-logs', 'system.activity-log.view', 'subject_id', ['bad']],
            'activity causer negative' => ['activity-logs', 'system.activity-log.view', 'causer_id', -1],
            'activity event too long' => ['activity-logs', 'system.activity-log.view', 'event', str_repeat('a', 256)],
            'dictionary type name too long' => ['dictionary-types', 'system.dictionary.view', 'name', str_repeat('a', 101)],
            'dictionary item type zero' => ['dictionary-items', 'system.dictionary.view', 'dictionary_type_id', 0],
            'dictionary item invalid boolean' => ['dictionary-items', 'system.dictionary.view', 'is_active', 'yes'],
            'configuration type too long' => ['system-configs', 'system.config.view', 'type', str_repeat('a', 31)],
            'configuration invalid boolean' => ['system-configs', 'system.config.view', 'is_public', 'yes'],
            'activity invalid date' => ['activity-logs', 'system.activity-log.view', 'created_at', ['not-a-date', '2026-10-02']],
            'login keyed date range' => ['login-logs', 'system.login-log.view', 'created_at', ['from' => '2026-10-01', 'to' => '2026-10-02']],
            'nested page size' => ['dictionary-items', 'system.dictionary.view', 'page_size', ['bad']],
        ];
    }

    public function test_nullable_filters_accept_empty_values_and_valid_scalar_boundaries(): void
    {
        $token = $this->managerTokenFor(['system.activity-log.view', 'system.login-log.view', 'system.dictionary.view', 'system.config.view']);
        $headers = ['Authorization' => 'Bearer '.$token];

        foreach ([
            'activity-logs' => ['log_name' => str_repeat('a', 255), 'event' => '', 'subject_type' => '', 'subject_id' => 1, 'causer_id' => '', 'created_at' => '', 'sorts' => ''],
            'login-logs' => ['guard' => '', 'event' => '', 'account' => str_repeat('a', 255), 'subject_id' => '', 'successful' => 'false', 'ip_address' => '::1', 'created_at' => ''],
            'dictionary-types' => ['name' => str_repeat('a', 100), 'code' => '', 'keyword' => '', 'is_active' => 'true'],
            'dictionary-items' => ['dictionary_type_id' => 1, 'type_code' => str_repeat('a', 100), 'code' => '', 'name' => '', 'value' => '', 'keyword' => '', 'is_active' => 'false'],
            'system-configs' => ['key' => str_repeat('a', 150), 'name' => '', 'config_group' => '', 'type' => str_repeat('a', 30), 'keyword' => '', 'is_public' => 'true', 'is_active' => 'false'],
        ] as $path => $filters) {
            $this->getJson(ApiRouting::adminPath('/'.$path).'?'.http_build_query($filters), $headers)->assertOk();
        }
    }

    public function test_configuration_filter_accepts_the_full_valid_key_length(): void
    {
        $key = str_repeat('a', 150);
        $configuration = SystemConfig::factory()->create(['key' => $key]);
        $token = $this->managerTokenFor(['system.config.view']);

        $this->getJson(ApiRouting::path('/admin/system-configs').'?'.http_build_query(['key' => $key]), [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $configuration->getKey());
    }
}

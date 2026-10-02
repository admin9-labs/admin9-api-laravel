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
            'activity subject array' => ['activity-logs', 'system.activity-log.view', 'subject_id', ['bad']],
            'activity invalid date' => ['activity-logs', 'system.activity-log.view', 'created_at', ['not-a-date', '2026-10-02']],
            'login keyed date range' => ['login-logs', 'system.login-log.view', 'created_at', ['from' => '2026-10-01', 'to' => '2026-10-02']],
            'nested page size' => ['dictionary-items', 'system.dictionary.view', 'page_size', ['bad']],
        ];
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

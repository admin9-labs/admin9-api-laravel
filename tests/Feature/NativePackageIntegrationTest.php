<?php

namespace Tests\Feature;

use App\Models\DictionaryItem;
use App\Models\Menu;
use App\Models\SystemConfig;
use App\Support\ApiRouting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class NativePackageIntegrationTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    #[DataProvider('invalidBusinessInputs')]
    public function test_early_validation_failures_do_not_pass_unvalidated_values_to_business_checks(string $operation): void
    {
        $menu = Menu::factory()->create()->refresh();
        $item = DictionaryItem::factory()->create()->refresh();
        $configuration = SystemConfig::factory()->create()->refresh();
        $headers = ['Authorization' => 'Bearer '.$this->managerTokenFor([
            'system.menu.create', 'system.menu.update',
            'system.dictionary.update', 'system.config.create', 'system.config.update',
        ])];
        $configurationCount = SystemConfig::query()->count();
        [$method, $path, $payload] = match ($operation) {
            'create menu' => ['POST', '/menus', ['type' => []]],
            'update menu' => ['PUT', '/menus/'.$menu->id, ['name' => [], 'type' => []]],
            'update dictionary item' => ['PUT', '/dictionary-items/'.$item->id, ['name' => [], 'code' => []]],
            'create configuration' => ['POST', '/system-configs', ['name' => [], 'type' => 'json', 'value' => []]],
            'update configuration' => ['PUT', '/system-configs/'.$configuration->id, ['name' => [], 'type' => 'json', 'value' => []]],
        };

        $this->json($method, ApiRouting::adminPath($path), $payload, $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name')
            ->assertHeader('X-Request-Id');

        $this->assertSame($menu->getAttributes(), $menu->fresh()->getAttributes());
        $this->assertSame($item->getAttributes(), $item->fresh()->getAttributes());
        $this->assertSame($configuration->getAttributes(), $configuration->fresh()->getAttributes());
        $this->assertDatabaseCount('menus', 1);
        $this->assertDatabaseCount('dictionary_items', 1);
        $this->assertDatabaseCount('system_configs', $configurationCount);
    }

    /** @return array<string, array{string}> */
    public static function invalidBusinessInputs(): array
    {
        return [
            'create menu' => ['create menu'],
            'update menu' => ['update menu'],
            'update dictionary item' => ['update dictionary item'],
            'create configuration' => ['create configuration'],
            'update configuration' => ['update configuration'],
        ];
    }
}

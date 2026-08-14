<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Support\Admin\SeededMenuProvisioner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class SeededMenuProvisionerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_tombstoned_parent_skips_itself_and_new_descendants(): void
    {
        DB::table('menu_seed_tombstones')->insert([
            'seed_key' => 'admin9.test.parent',
            'deleted_at' => now(),
        ]);

        $warnings = app(SeededMenuProvisioner::class)->provision([
            $this->definition('admin9.test.parent', null, 'test.parent', Menu::TYPE_DIRECTORY),
            $this->definition('admin9.test.child', 'admin9.test.parent', 'test.child', Menu::TYPE_PAGE),
        ]);

        $this->assertCount(2, $warnings);
        $this->assertDatabaseMissing('menus', ['seed_key' => 'admin9.test.parent']);
        $this->assertDatabaseMissing('menus', ['seed_key' => 'admin9.test.child']);
    }

    public function test_default_code_collision_fails_atomically(): void
    {
        Menu::factory()->directory()->create(['code' => 'test.conflict']);

        try {
            app(SeededMenuProvisioner::class)->provision([
                $this->definition('admin9.test.created-first', null, 'test.created-first', Menu::TYPE_DIRECTORY),
                $this->definition('admin9.test.conflict', null, 'test.conflict', Menu::TYPE_DIRECTORY),
            ]);
            $this->fail('Expected the default code collision to abort provisioning.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('default code [test.conflict]', $exception->getMessage());
        }

        $this->assertDatabaseMissing('menus', ['seed_key' => 'admin9.test.created-first']);
        $this->assertDatabaseMissing('menus', ['seed_key' => 'admin9.test.conflict']);
    }

    /**
     * @return array{seed_key: string, parent_seed_key: ?string, code: string, name: string, path: ?string, component: ?string, icon: ?string, type: string, permission_names: array<int, string>, sort: int, is_visible: bool, is_active: bool}
     */
    private function definition(string $seedKey, ?string $parentSeedKey, string $code, string $type): array
    {
        return [
            'seed_key' => $seedKey,
            'parent_seed_key' => $parentSeedKey,
            'code' => $code,
            'name' => $code,
            'path' => null,
            'component' => null,
            'icon' => null,
            'type' => $type,
            'permission_names' => [],
            'sort' => 0,
            'is_visible' => true,
            'is_active' => true,
        ];
    }
}

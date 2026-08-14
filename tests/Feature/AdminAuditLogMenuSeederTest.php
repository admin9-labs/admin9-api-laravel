<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Permission;
use Database\Seeders\AdminAuditLogMenuSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AdminAuditLogMenuSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seeder_is_idempotent_and_preserves_all_menu_and_binding_edits(): void
    {
        $system = Menu::factory()->directory()->create(['code' => 'system']);
        $system->setAttribute('seed_key', 'admin9.core.system');
        $system->save();
        $activityPermission = $this->createPermission('system.activity-log.view');
        $loginPermission = $this->createPermission('system.login-log.view');
        $extraPermission = $this->createPermission('dynamic.extra.view');
        $seeder = new AdminAuditLogMenuSeeder;

        $seeder->run();
        $menu = Menu::query()->where('code', 'system.logs')->firstOrFail();
        $menuId = $menu->id;
        $menu->update([
            'parent_id' => null,
            'name' => 'Drifted',
            'code' => 'renamed.logs',
            'path' => '/wrong',
            'component' => 'wrong/index',
            'icon' => null,
            'sort' => 1,
            'is_visible' => false,
            'is_active' => false,
        ]);
        $menu->permissions()->sync([$extraPermission->id]);

        $seeder->run();
        $menu->refresh();

        $this->assertSame($menuId, $menu->id);
        $this->assertSame(1, Menu::query()->where('seed_key', 'admin9.core.system.logs')->count());
        $this->assertSame(0, Menu::query()->where('code', 'system.logs')->count());
        $this->assertNull($menu->parent_id);
        $this->assertSame('Drifted', $menu->name);
        $this->assertSame('renamed.logs', $menu->code);
        $this->assertSame('/wrong', $menu->path);
        $this->assertSame('wrong/index', $menu->component);
        $this->assertNull($menu->icon);
        $this->assertSame(Menu::TYPE_PAGE, $menu->type);
        $this->assertSame(1, $menu->sort);
        $this->assertFalse($menu->is_visible);
        $this->assertFalse($menu->is_active);
        $this->assertSame([$extraPermission->id], $menu->permissions()->pluck('permissions.id')->all());
    }

    public function test_seeder_fails_when_system_parent_menu_is_missing(): void
    {
        $this->createPermission('system.activity-log.view');
        $this->createPermission('system.login-log.view');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('required parent [admin9.core.system] is missing');

        (new AdminAuditLogMenuSeeder)->run();
    }

    public function test_seeder_fails_atomically_when_a_required_permission_is_missing(): void
    {
        $system = Menu::factory()->directory()->create(['code' => 'system']);
        $system->setAttribute('seed_key', 'admin9.core.system');
        $system->save();
        $this->createPermission('system.activity-log.view');

        try {
            (new AdminAuditLogMenuSeeder)->run();
            $this->fail('Expected missing login log permission to abort the seeder.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('system.login-log.view', $exception->getMessage());
        }

        $this->assertDatabaseMissing('menus', ['code' => 'system.logs']);
    }

    public function test_deleted_seeded_menu_is_not_recreated(): void
    {
        $system = Menu::factory()->directory()->create(['code' => 'system']);
        $system->setAttribute('seed_key', 'admin9.core.system');
        $system->save();
        $this->createPermission('system.activity-log.view');
        $this->createPermission('system.login-log.view');
        $seeder = new AdminAuditLogMenuSeeder;
        $seeder->run();

        $menu = Menu::query()->where('seed_key', 'admin9.core.system.logs')->firstOrFail();
        DB::table('menu_seed_tombstones')->insert([
            'seed_key' => 'admin9.core.system.logs',
            'deleted_at' => now(),
        ]);
        $menu->delete();

        $seeder->run();

        $this->assertDatabaseMissing('menus', ['seed_key' => 'admin9.core.system.logs']);
        $this->assertDatabaseCount('menu_seed_tombstones', 1);
    }

    private function createPermission(string $name): Permission
    {
        return Permission::query()->create([
            'name' => $name,
            'guard_name' => 'admin',
        ]);
    }
}

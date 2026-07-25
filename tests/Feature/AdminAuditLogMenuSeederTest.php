<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Permission;
use Database\Seeders\AdminAuditLogMenuSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Tests\TestCase;

class AdminAuditLogMenuSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seeder_is_idempotent_and_restores_authoritative_menu_and_bindings(): void
    {
        $system = Menu::factory()->directory()->create(['code' => 'system']);
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
            'path' => '/wrong',
            'component' => 'wrong/index',
            'sort' => 1,
            'is_visible' => false,
            'is_active' => false,
        ]);
        $menu->permissions()->sync([$extraPermission->id]);

        $seeder->run();
        $menu->refresh();

        $this->assertSame($menuId, $menu->id);
        $this->assertSame(1, Menu::query()->where('code', 'system.logs')->count());
        $this->assertSame($system->id, $menu->parent_id);
        $this->assertSame('日志管理', $menu->name);
        $this->assertSame('/system/log', $menu->path);
        $this->assertSame('system/log/index', $menu->component);
        $this->assertSame('file', $menu->icon);
        $this->assertSame(Menu::TYPE_PAGE, $menu->type);
        $this->assertSame(70, $menu->sort);
        $this->assertTrue($menu->is_visible);
        $this->assertTrue($menu->is_active);
        $this->assertEqualsCanonicalizing([
            $activityPermission->id,
            $loginPermission->id,
        ], $menu->permissions()->pluck('permissions.id')->all());
    }

    public function test_seeder_fails_when_system_parent_menu_is_missing(): void
    {
        $this->createPermission('system.activity-log.view');
        $this->createPermission('system.login-log.view');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('required parent menu [system] is missing');

        (new AdminAuditLogMenuSeeder)->run();
    }

    public function test_seeder_fails_atomically_when_a_required_permission_is_missing(): void
    {
        Menu::factory()->directory()->create(['code' => 'system']);
        $this->createPermission('system.activity-log.view');

        try {
            (new AdminAuditLogMenuSeeder)->run();
            $this->fail('Expected missing login log permission to abort the seeder.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('system.login-log.view', $exception->getMessage());
        }

        $this->assertDatabaseMissing('menus', ['code' => 'system.logs']);
    }

    private function createPermission(string $name): Permission
    {
        return Permission::query()->create([
            'name' => $name,
            'guard_name' => 'admin',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Http\Resources\Admin\DictionaryItemResource;
use App\Http\Resources\Admin\DictionaryTypeResource;
use App\Http\Resources\Admin\MenuResource;
use App\Http\Resources\Admin\PermissionResource;
use App\Http\Resources\Admin\RoleResource;
use App\Http\Resources\Admin\SystemConfigResource;
use App\Http\Resources\Admin\UserResource;
use App\Http\Resources\Member\MemberResource;
use App\Models\DictionaryItem;
use App\Models\DictionaryType;
use App\Models\Member;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\SystemConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResourceSerializationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_assigned_resources_serialize_datetime_fields_consistently(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:34:56'));

        $permission = Permission::query()->create([
            'name' => 'resource.datetime.view',
            'guard_name' => 'admin',
        ]);
        $role = Role::findOrCreate('resource-datetime-role', 'admin');
        $menu = Menu::factory()->create();
        $dictionaryType = DictionaryType::factory()->create();
        $dictionaryItem = DictionaryItem::factory()->create([
            'dictionary_type_id' => $dictionaryType->id,
        ]);
        $systemConfig = SystemConfig::factory()->create();
        $user = User::factory()->create(['last_login_at' => now()]);
        $member = Member::factory()->create(['last_login_at' => now()]);
        $request = Request::create('/resource-serialization-test');

        $this->assertSame('2026-06-27 12:34:56', DictionaryItemResource::make($dictionaryItem)->resolve($request)['created_at']);
        $this->assertSame('2026-06-27 12:34:56', DictionaryItemResource::make($dictionaryItem)->resolve($request)['updated_at']);
        $this->assertSame('2026-06-27 12:34:56', DictionaryTypeResource::make($dictionaryType)->resolve($request)['created_at']);
        $this->assertSame('2026-06-27 12:34:56', DictionaryTypeResource::make($dictionaryType)->resolve($request)['updated_at']);
        $this->assertSame('2026-06-27 12:34:56', PermissionResource::make($permission)->resolve($request)['created_at']);
        $this->assertSame('2026-06-27 12:34:56', PermissionResource::make($permission)->resolve($request)['updated_at']);
        $this->assertSame('2026-06-27 12:34:56', MenuResource::make($menu)->resolve($request)['created_at']);
        $this->assertSame('2026-06-27 12:34:56', MenuResource::make($menu)->resolve($request)['updated_at']);
        $this->assertSame('2026-06-27 12:34:56', RoleResource::make($role)->resolve($request)['created_at']);
        $this->assertSame('2026-06-27 12:34:56', RoleResource::make($role)->resolve($request)['updated_at']);
        $this->assertSame('2026-06-27 12:34:56', SystemConfigResource::make($systemConfig)->resolve($request)['created_at']);
        $this->assertSame('2026-06-27 12:34:56', SystemConfigResource::make($systemConfig)->resolve($request)['updated_at']);
        $this->assertSame('2026-06-27 12:34:56', UserResource::make($user)->resolve($request)['last_login_at']);
        $this->assertSame('2026-06-27 12:34:56', UserResource::make($user)->resolve($request)['created_at']);
        $this->assertSame('2026-06-27 12:34:56', UserResource::make($user)->resolve($request)['updated_at']);
        $this->assertSame('2026-06-27 12:34:56', MemberResource::make($member)->resolve($request)['last_login_at']);
    }

    public function test_permission_resource_includes_canonical_metadata(): void
    {
        $permission = Permission::query()->create([
            'name' => 'resource.metadata.view',
            'guard_name' => 'admin',
            'display_name' => '元数据查看',
            'group' => 'resource.metadata',
            'description' => 'Canonical metadata',
            'sort' => 10,
            'is_system' => false,
            'is_active' => true,
        ]);

        $resource = PermissionResource::make($permission)->resolve(Request::create('/resource-serialization-test'));

        $this->assertSame($permission->id, $resource['id']);
        $this->assertSame('resource.metadata.view', $resource['name']);
        $this->assertSame('admin', $resource['guard_name']);
        $this->assertSame('元数据查看', $resource['display_name']);
        $this->assertSame('resource.metadata', $resource['group']);
        $this->assertSame('Canonical metadata', $resource['description']);
        $this->assertSame(10, $resource['sort']);
        $this->assertFalse($resource['is_system']);
        $this->assertTrue($resource['is_active']);
    }

    public function test_menu_resource_includes_canonical_permission_id(): void
    {
        $permission = Permission::query()->create([
            'name' => 'resource.menu.view',
            'guard_name' => 'admin',
        ]);
        $menu = Menu::factory()->create([
            'code' => 'resource.compat.menu',
            'permission_id' => $permission->id,
            'permission_name' => $permission->name,
        ]);

        $resource = MenuResource::make($menu)->resolve(Request::create('/resource-serialization-test'));

        $this->assertSame('resource.compat.menu', $resource['code']);
        $this->assertSame($permission->id, $resource['permission_id']);
        $this->assertSame('resource.menu.view', $resource['permission_name']);
        $this->assertArrayHasKey('created_at', $resource);
        $this->assertArrayHasKey('updated_at', $resource);
    }
}

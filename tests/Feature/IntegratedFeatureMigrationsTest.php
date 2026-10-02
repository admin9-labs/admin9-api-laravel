<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\FileDirectory;
use App\Models\Member;
use App\Models\MemberAuthSession;
use App\Models\Permission;
use App\Support\ApiRouting;
use Database\Seeders\AdminRbacSeeder;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class IntegratedFeatureMigrationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public function test_permission_rollback_preserves_seeded_menu_bindings_and_role_grants(): void
    {
        $this->seed(AdminRbacSeeder::class);
        $permission = Permission::query()->where('name', 'system.file.update')->sole();
        $menuIds = $permission->menus()->pluck('menus.id')->all();
        $this->assertNotEmpty($menuIds);
        $role = Role::findByName('system-admin', 'admin');
        $migration = require database_path('migrations/2026_10_02_093419_add_file_update_permission.php');

        $migration->down();

        $this->assertModelExists($permission);
        $this->assertSame($menuIds, $permission->menus()->pluck('menus.id')->all());
        $this->assertTrue($role->hasPermissionTo('system.file.update'));
    }

    public function test_permission_upgrade_and_rollback_preserve_preexisting_custom_configuration(): void
    {
        $permission = Permission::query()->where('name', 'system.file.update')->sole();
        $permission->forceFill(['display_name' => 'Custom move permission', 'is_system' => false])->save();
        $customRole = Role::findOrCreate('custom-files', 'admin');
        $customRole->givePermissionTo($permission);
        $systemRole = Role::findOrCreate('system-admin', 'admin');
        $migration = require database_path('migrations/2026_10_02_093419_add_file_update_permission.php');

        $migration->up();
        $migration->up();
        $migration->down();

        $this->assertSame('Custom move permission', $permission->refresh()->display_name);
        $this->assertFalse($permission->is_system);
        $this->assertTrue($customRole->hasPermissionTo($permission));
        $this->assertTrue($systemRole->hasPermissionTo($permission));
        $this->assertSame(1, Permission::query()->where('name', 'system.file.update')->count());
    }

    public function test_directory_schema_round_trip_preserves_file_metadata(): void
    {
        $file = File::factory()->create(['directory_id' => FileDirectory::factory()->create()->id]);
        $attributes = $file->refresh()->getRawOriginal();
        unset($attributes['directory_id']);
        $migration = require database_path('migrations/2026_10_02_093418_create_file_directories_table.php');

        $migration->down();
        $this->assertSame($attributes, $file->refresh()->getRawOriginal());
        $migration->up();

        $this->assertModelExists($file);
        $this->assertNull($file->refresh()->directory_id);
        $this->assertSame(0, FileDirectory::query()->count());
    }

    public function test_session_schema_upgrade_preserves_members_and_adopts_legacy_tokens(): void
    {
        $member = Member::factory()->create();
        $legacy = Auth::guard('member')->login($member);
        $sessions = require database_path('migrations/2026_10_02_075246_create_member_auth_sessions_table.php');
        $refreshes = require database_path('migrations/2026_10_02_081636_create_member_token_refreshes_table.php');

        $refreshes->down();
        $sessions->down();
        $this->assertModelExists($member);
        $sessions->up();
        $refreshes->up();

        $this->assertSame(0, MemberAuthSession::query()->count());
        $this->getJson(ApiRouting::path('/auth/me'), ['Authorization' => 'Bearer '.$legacy])->assertOk();
        $this->postJson(ApiRouting::path('/auth/refresh'), headers: ['Authorization' => 'Bearer '.$legacy])->assertOk();
        $this->assertSame($member->id, MemberAuthSession::query()->sole()->member_id);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'name' => 'system.file.update',
            'guard_name' => 'admin',
            'display_name' => '文件分组移动',
            'group' => 'system.file',
            'description' => '移动内容文件至其他分组或未分组',
            'sort' => 955,
            'is_system' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('name', 'system.file.update')->where('guard_name', 'admin')->value('id');
        $roleId = DB::table('roles')->where('name', 'system-admin')->where('guard_name', 'admin')->value('id');
        if ($roleId !== null) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Preserve permissions and grants that may predate this migration when rolling back code. */
    public function down(): void {}
};

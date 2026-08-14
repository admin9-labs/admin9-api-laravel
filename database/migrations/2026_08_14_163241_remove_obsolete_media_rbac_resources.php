<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->transaction(function () use ($connection): void {
            $legacyPage = $connection->table('menus')
                ->where('code', 'system.media')
                ->where('path', '/system/media')
                ->where('component', 'system/media/index')
                ->where('type', 'page')
                ->first(['id']);
            $menuIds = collect();

            if ($legacyPage !== null) {
                $childIds = $connection->table('menus')
                    ->where('parent_id', $legacyPage->id)
                    ->where('type', 'button')
                    ->whereIn('code', ['system.media.create', 'system.media.delete'])
                    ->pluck('id');
                $menuIds = $childIds->push($legacyPage->id);
            }

            if ($menuIds->isNotEmpty()) {
                if (Schema::connection($this->getConnection())->hasTable('role_menu')) {
                    $connection->table('role_menu')->whereIn('menu_id', $menuIds)->delete();
                }

                if (Schema::connection($this->getConnection())->hasTable('menu_permission')) {
                    $connection->table('menu_permission')->whereIn('menu_id', $menuIds)->delete();
                }

                $connection->table('menus')->whereIn('id', $menuIds->reject(
                    fn (mixed $menuId): bool => (int) $menuId === (int) $legacyPage->id
                ))->delete();
                $connection->table('menus')->where('id', $legacyPage->id)->delete();
            }

            $legacyPermissionIds = $connection->table('permissions')
                ->where('guard_name', 'admin')
                ->whereIn('name', ['system.media.view', 'system.media.create', 'system.media.delete'])
                ->pluck('id');

            if ($legacyPermissionIds->isNotEmpty() && Schema::connection($this->getConnection())->hasTable('menu_permission')) {
                $connection->table('menu_permission')->whereIn('permission_id', $legacyPermissionIds)->delete();
            }

            $connection->table('permissions')->whereIn('id', $legacyPermissionIds)->delete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};

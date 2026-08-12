<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->transaction(function () use ($connection): void {
            $connection->table('menus')
                ->where('code', 'system.configs')
                ->where('name', '系统配置')
                ->update(['name' => '系统设置']);

            $obsoleteMenuIds = $connection->table('menus')
                ->whereIn('code', ['system.configs.create', 'system.configs.delete'])
                ->pluck('id');

            if ($obsoleteMenuIds->isEmpty()) {
                return;
            }

            $schema = $connection->getSchemaBuilder();

            if ($schema->hasTable('role_menu')) {
                $connection->table('role_menu')->whereIn('menu_id', $obsoleteMenuIds)->delete();
            }

            if ($schema->hasTable('menu_permission')) {
                $connection->table('menu_permission')->whereIn('menu_id', $obsoleteMenuIds)->delete();
            }

            $connection->table('menus')->whereIn('id', $obsoleteMenuIds)->delete();
        });
    }

    /**
     * The removed menu and role bindings cannot be reconstructed reliably, so
     * rollback intentionally preserves the migrated system settings menu.
     */
    public function down(): void {}
};

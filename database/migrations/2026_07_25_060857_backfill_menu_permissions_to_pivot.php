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
            $menus = $connection->table('menus')
                ->whereNotNull('permission_id')
                ->select(['id', 'code', 'permission_id'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $permissions = $connection->table('permissions')
                ->whereIn('id', $menus->pluck('permission_id')->all())
                ->select(['id', 'guard_name'])
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (object $permission): int => (int) $permission->id);
            $errors = [];

            foreach ($menus as $menu) {
                $permissionId = (int) $menu->permission_id;
                $permission = $permissions->get($permissionId);

                if ($permission === null) {
                    $errors[] = sprintf(
                        'menu [id=%d, code=%s] references missing permission_id [%d].',
                        $menu->id,
                        $menu->code,
                        $permissionId,
                    );

                    continue;
                }

                if ($permission->guard_name !== 'admin') {
                    $errors[] = sprintf(
                        'menu [id=%d, code=%s] references permission_id [%d] with non-admin guard [%s].',
                        $menu->id,
                        $menu->code,
                        $permissionId,
                        $permission->guard_name,
                    );
                }
            }

            if ($errors !== []) {
                throw new RuntimeException("Cannot migrate menu permissions to the pivot:\n- ".implode("\n- ", $errors));
            }

            $menus
                ->map(fn (object $menu): array => [
                    'menu_id' => (int) $menu->id,
                    'permission_id' => (int) $menu->permission_id,
                ])
                ->chunk(500)
                ->each(fn ($rows) => $connection->table('menu_permission')->insertOrIgnore($rows->all()));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection($this->getConnection())->table('menu_permission')->delete();
    }
};

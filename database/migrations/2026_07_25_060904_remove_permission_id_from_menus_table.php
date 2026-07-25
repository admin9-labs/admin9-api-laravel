<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        $unmigratedMenu = $connection->table('menus')
            ->whereNotNull('permission_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('menu_permission')
                    ->whereColumn('menu_permission.menu_id', 'menus.id')
                    ->whereColumn('menu_permission.permission_id', 'menus.permission_id');
            })
            ->select(['id', 'code', 'permission_id'])
            ->orderBy('id')
            ->first();

        if ($unmigratedMenu !== null) {
            throw new RuntimeException(sprintf(
                'Cannot remove menus.permission_id because menu [id=%d, code=%s] permission_id [%d] was not backfilled.',
                $unmigratedMenu->id,
                $unmigratedMenu->code,
                $unmigratedMenu->permission_id,
            ));
        }

        Schema::connection($this->getConnection())->table('menus', function (Blueprint $table): void {
            $table->dropForeign(['permission_id']);
            $table->dropIndex(['permission_id']);
            $table->dropColumn('permission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        $menuWithMultiplePermissions = $connection->table('menu_permission')
            ->select('menu_id')
            ->groupBy('menu_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('menu_id')
            ->first();

        if ($menuWithMultiplePermissions !== null) {
            throw new RuntimeException(sprintf(
                'Cannot restore menus.permission_id without data loss because menu_id [%d] has multiple permissions.',
                $menuWithMultiplePermissions->menu_id,
            ));
        }

        Schema::connection($this->getConnection())->table('menus', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id')->nullable();
            $table->index('permission_id', 'menus_permission_id_index');
            $table->foreign('permission_id', 'menus_permission_id_foreign')
                ->references('id')
                ->on('permissions')
                ->restrictOnDelete();
        });

        $connection->transaction(function () use ($connection): void {
            $connection->table('menu_permission')
                ->select(['menu_id', 'permission_id'])
                ->orderBy('menu_id')
                ->get()
                ->each(function (object $binding) use ($connection): void {
                    $connection->table('menus')
                        ->where('id', $binding->menu_id)
                        ->update(['permission_id' => $binding->permission_id]);
                });
        });
    }
};

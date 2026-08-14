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
        Schema::table('menus', function (Blueprint $table): void {
            $table->string('seed_key', 100)->nullable()->unique()->after('id');
        });

        Schema::create('menu_seed_tombstones', function (Blueprint $table): void {
            $table->string('seed_key', 100)->primary();
            $table->timestamp('deleted_at');
        });

        foreach ($this->seedKeysByCanonicalCode() as $code => $seedKey) {
            DB::table('menus')
                ->where('code', $code)
                ->whereNull('seed_key')
                ->update(['seed_key' => $seedKey]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('menu_seed_tombstones')->exists()) {
            throw new LogicException('Cannot remove menu seed identities while deletion tombstones exist.');
        }

        foreach ($this->seedKeysByCanonicalCode() as $code => $seedKey) {
            $seededMenuUsesAnotherCode = DB::table('menus')
                ->where('seed_key', $seedKey)
                ->where('code', '!=', $code)
                ->exists();

            if ($seededMenuUsesAnotherCode) {
                throw new LogicException('Cannot remove menu seed identities after a seeded menu code has changed.');
            }
        }

        Schema::dropIfExists('menu_seed_tombstones');

        Schema::table('menus', function (Blueprint $table): void {
            $table->dropUnique('menus_seed_key_unique');
            $table->dropColumn('seed_key');
        });
    }

    /**
     * Historical identities are backfilled only from exact canonical codes.
     *
     * @return array<string, string>
     */
    private function seedKeysByCanonicalCode(): array
    {
        return [
            'system' => 'admin9.core.system',
            'system.roles' => 'admin9.core.system.roles',
            'system.roles.create' => 'admin9.core.system.roles.create',
            'system.roles.update' => 'admin9.core.system.roles.update',
            'system.roles.delete' => 'admin9.core.system.roles.delete',
            'system.permissions' => 'admin9.core.system.permissions',
            'system.permissions.create' => 'admin9.core.system.permissions.create',
            'system.permissions.update' => 'admin9.core.system.permissions.update',
            'system.permissions.delete' => 'admin9.core.system.permissions.delete',
            'system.users' => 'admin9.core.system.users',
            'system.users.create' => 'admin9.core.system.users.create',
            'system.users.update' => 'admin9.core.system.users.update',
            'system.users.delete' => 'admin9.core.system.users.delete',
            'system.users.assign-role' => 'admin9.core.system.users.assign-role',
            'SystemMember' => 'admin9.core.system.members',
            'SystemMember.create' => 'admin9.core.system.members.create',
            'SystemMember.update' => 'admin9.core.system.members.update',
            'SystemMember.status' => 'admin9.core.system.members.status',
            'SystemMember.reset_password' => 'admin9.core.system.members.reset-password',
            'SystemMember.invalidate_sessions' => 'admin9.core.system.members.invalidate-sessions',
            'system.file' => 'admin9.core.system.files',
            'system.file.create' => 'admin9.core.system.files.create',
            'system.file.delete' => 'admin9.core.system.files.delete',
            'system.menus' => 'admin9.core.system.menus',
            'system.menus.create' => 'admin9.core.system.menus.create',
            'system.menus.update' => 'admin9.core.system.menus.update',
            'system.menus.delete' => 'admin9.core.system.menus.delete',
            'system.dictionaries' => 'admin9.core.system.dictionaries',
            'system.dictionaries.create' => 'admin9.core.system.dictionaries.create',
            'system.dictionaries.update' => 'admin9.core.system.dictionaries.update',
            'system.dictionaries.delete' => 'admin9.core.system.dictionaries.delete',
            'system.configs' => 'admin9.core.system.settings',
            'system.configs.update' => 'admin9.core.system.settings.update',
            'system.logs' => 'admin9.core.system.logs',
        ];
    }
};

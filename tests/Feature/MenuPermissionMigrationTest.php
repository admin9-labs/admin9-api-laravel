<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MenuPermissionMigrationTest extends TestCase
{
    private const CONNECTION = 'menu-permission-migration-test';

    private string $databasePath;

    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $databasePath = tempnam(sys_get_temp_dir(), 'admin9-menu-permission-');

        if ($databasePath === false) {
            throw new RuntimeException('Unable to create a temporary SQLite database.');
        }

        $this->databasePath = $databasePath;
        $this->originalDefaultConnection = (string) config('database.default');

        config([
            'database.default' => self::CONNECTION,
            'database.connections.'.self::CONNECTION => array_replace(
                config('database.connections.sqlite'),
                [
                    'database' => $this->databasePath,
                    'foreign_key_constraints' => true,
                ],
            ),
        ]);

        DB::purge(self::CONNECTION);

        $schema = Schema::connection(self::CONNECTION);
        $schema->create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->unique(['name', 'guard_name']);
        });
        $schema->create('menus', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('permission_name')->nullable()->index();
            $table->foreignId('permission_id')->nullable()->constrained('permissions')->nullOnDelete();
        });
    }

    protected function tearDown(): void
    {
        config(['database.default' => $this->originalDefaultConnection]);

        DB::disconnect(self::CONNECTION);
        DB::purge(self::CONNECTION);

        if (isset($this->databasePath) && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_legal_legacy_names_are_backfilled_before_the_legacy_column_is_removed(): void
    {
        $database = DB::connection(self::CONNECTION);
        $permissionId = $this->insertPermission('legacy.menu.view', 'admin');
        $nameOnlyMenuId = $this->insertMenu('legacy.name-only', null, 'legacy.menu.view');
        $canonicalMenuId = $this->insertMenu('legacy.canonical', $permissionId, 'legacy.menu.view');
        $publicMenuId = $this->insertMenu('legacy.public', null, null);

        $this->dataMigration()->up();

        $this->assertSame($permissionId, $database->table('menus')->where('id', $nameOnlyMenuId)->value('permission_id'));
        $this->assertSame($permissionId, $database->table('menus')->where('id', $canonicalMenuId)->value('permission_id'));
        $this->assertNull($database->table('menus')->where('id', $publicMenuId)->value('permission_id'));

        $this->schemaMigration()->up();

        $this->assertFalse(Schema::connection(self::CONNECTION)->hasColumn('menus', 'permission_name'));

        $this->expectException(QueryException::class);

        $database->table('permissions')->where('id', $permissionId)->delete();
    }

    public function test_rollback_restores_legacy_name_and_null_on_delete_schema(): void
    {
        $database = DB::connection(self::CONNECTION);
        $permissionId = $this->insertPermission('legacy.rollback.view', 'admin');
        $menuId = $this->insertMenu('legacy.rollback', null, 'legacy.rollback.view');
        $dataMigration = $this->dataMigration();
        $schemaMigration = $this->schemaMigration();

        $dataMigration->up();
        $schemaMigration->up();
        $schemaMigration->down();
        $dataMigration->down();

        $this->assertTrue(Schema::connection(self::CONNECTION)->hasColumn('menus', 'permission_name'));
        $this->assertSame('legacy.rollback.view', $database->table('menus')->where('id', $menuId)->value('permission_name'));

        $database->table('permissions')->where('id', $permissionId)->delete();

        $this->assertNull($database->table('menus')->where('id', $menuId)->value('permission_id'));
    }

    public function test_unresolved_legacy_name_aborts_without_partial_backfill(): void
    {
        $database = DB::connection(self::CONNECTION);
        $this->insertPermission('legacy.valid.view', 'admin');
        $validMenuId = $this->insertMenu('legacy.valid', null, 'legacy.valid.view');
        $invalidMenuId = $this->insertMenu('legacy.missing', null, 'legacy.missing.view');

        try {
            $this->dataMigration()->up();
            $this->fail('Expected unresolved legacy permission data to abort the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("menu [id={$invalidMenuId}, code=legacy.missing]", $exception->getMessage());
            $this->assertStringContainsString('does not resolve to an admin permission', $exception->getMessage());
        }

        $this->assertNull($database->table('menus')->where('id', $validMenuId)->value('permission_id'));
        $this->assertNull($database->table('menus')->where('id', $invalidMenuId)->value('permission_id'));
        $this->assertTrue(Schema::connection(self::CONNECTION)->hasColumn('menus', 'permission_name'));
    }

    public function test_non_admin_legacy_name_aborts_with_guard_diagnostics(): void
    {
        $this->insertPermission('legacy.member.view', 'member');
        $menuId = $this->insertMenu('legacy.member', null, 'legacy.member.view');

        try {
            $this->dataMigration()->up();
            $this->fail('Expected non-admin legacy permission data to abort the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("menu [id={$menuId}, code=legacy.member]", $exception->getMessage());
            $this->assertStringContainsString('matches only non-admin guard(s) [member]', $exception->getMessage());
        }

        $this->assertNull(DB::connection(self::CONNECTION)->table('menus')->where('id', $menuId)->value('permission_id'));
    }

    public function test_non_admin_permission_id_aborts_with_guard_diagnostics(): void
    {
        $permissionId = $this->insertPermission('legacy.member.id', 'member');
        $menuId = $this->insertMenu('legacy.member-id', $permissionId, null);

        try {
            $this->dataMigration()->up();
            $this->fail('Expected a non-admin permission ID to abort the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("menu [id={$menuId}, code=legacy.member-id]", $exception->getMessage());
            $this->assertStringContainsString("permission_id [{$permissionId}] with non-admin guard [member]", $exception->getMessage());
        }

        $this->assertSame($permissionId, DB::connection(self::CONNECTION)->table('menus')->where('id', $menuId)->value('permission_id'));
    }

    public function test_conflicting_legacy_name_and_permission_id_abort_with_both_values(): void
    {
        $permissionId = $this->insertPermission('legacy.canonical.view', 'admin');
        $this->insertPermission('legacy.stale.view', 'admin');
        $menuId = $this->insertMenu('legacy.conflict', $permissionId, 'legacy.stale.view');

        try {
            $this->dataMigration()->up();
            $this->fail('Expected conflicting legacy permission data to abort the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("menu [id={$menuId}, code=legacy.conflict]", $exception->getMessage());
            $this->assertStringContainsString("permission_id [{$permissionId}] name [legacy.canonical.view]", $exception->getMessage());
            $this->assertStringContainsString('legacy permission_name [legacy.stale.view]', $exception->getMessage());
        }

        $this->assertSame($permissionId, DB::connection(self::CONNECTION)->table('menus')->where('id', $menuId)->value('permission_id'));
    }

    private function insertPermission(string $name, string $guardName): int
    {
        return DB::connection(self::CONNECTION)->table('permissions')->insertGetId([
            'name' => $name,
            'guard_name' => $guardName,
        ]);
    }

    private function insertMenu(string $code, ?int $permissionId, ?string $permissionName): int
    {
        return DB::connection(self::CONNECTION)->table('menus')->insertGetId([
            'code' => $code,
            'permission_id' => $permissionId,
            'permission_name' => $permissionName,
        ]);
    }

    private function dataMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_07_20_181457_backfill_menu_permission_ids_from_legacy_names.php');

        return $migration;
    }

    private function schemaMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_07_20_181531_enforce_menu_permission_id_as_single_source.php');

        return $migration;
    }
}

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
            $table->index('permission_id');
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

    public function test_scalar_permission_is_backfilled_to_unique_restricting_and_cascading_pivot(): void
    {
        $database = DB::connection(self::CONNECTION);
        $permissionId = $this->insertPermission('pivot.backfill.view', 'admin');
        $menuId = $this->insertMenu('pivot.backfill', $permissionId, null);

        $this->schemaMigration()->up();
        $this->pivotTableMigration()->up();

        $reverseLookupIndex = collect(Schema::connection(self::CONNECTION)->getIndexes('menu_permission'))
            ->firstWhere('name', 'menu_permission_permission_id_index');

        $this->assertNotNull($reverseLookupIndex);
        $this->assertSame(['permission_id'], $reverseLookupIndex['columns']);
        $this->assertFalse($reverseLookupIndex['unique']);

        $this->pivotBackfillMigration()->up();
        $this->removeScalarPermissionMigration()->up();

        $this->assertFalse(Schema::connection(self::CONNECTION)->hasColumn('menus', 'permission_id'));
        $this->assertDatabaseHas('menu_permission', [
            'menu_id' => $menuId,
            'permission_id' => $permissionId,
        ], self::CONNECTION);

        try {
            $database->table('menu_permission')->insert([
                'menu_id' => $menuId,
                'permission_id' => $permissionId,
            ]);
            $this->fail('Expected duplicate menu permission binding to violate the composite primary key.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            $database->table('permissions')->where('id', $permissionId)->delete();
            $this->fail('Expected a referenced permission delete to be restricted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $database->table('menus')->where('id', $menuId)->delete();

        $this->assertSame(0, $database->table('menu_permission')->where('menu_id', $menuId)->count());
        $this->assertSame(1, $database->table('permissions')->where('id', $permissionId)->delete());
    }

    public function test_pivot_backfill_rejects_non_admin_permission_without_partial_writes(): void
    {
        $permissionId = $this->insertPermission('pivot.member.view', 'member');
        $menuId = $this->insertMenu('pivot.member', $permissionId, null);
        $this->pivotTableMigration()->up();

        try {
            $this->pivotBackfillMigration()->up();
            $this->fail('Expected the pivot backfill to reject a non-admin permission.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("menu [id={$menuId}, code=pivot.member]", $exception->getMessage());
            $this->assertStringContainsString('non-admin guard [member]', $exception->getMessage());
        }

        $this->assertSame(0, DB::connection(self::CONNECTION)->table('menu_permission')->count());
    }

    public function test_scalar_rollback_refuses_multi_permission_menu_before_changing_schema(): void
    {
        $database = DB::connection(self::CONNECTION);
        $firstPermissionId = $this->insertPermission('pivot.rollback.first', 'admin');
        $secondPermissionId = $this->insertPermission('pivot.rollback.second', 'admin');
        $menuId = $this->insertMenu('pivot.rollback', $firstPermissionId, null);
        $removeScalarPermission = $this->removeScalarPermissionMigration();

        $this->schemaMigration()->up();
        $this->pivotTableMigration()->up();
        $this->pivotBackfillMigration()->up();
        $removeScalarPermission->up();
        $database->table('menu_permission')->insert([
            'menu_id' => $menuId,
            'permission_id' => $secondPermissionId,
        ]);

        try {
            $removeScalarPermission->down();
            $this->fail('Expected rollback to reject a menu with multiple permissions.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("menu_id [{$menuId}] has multiple permissions", $exception->getMessage());
        }

        $this->assertFalse(Schema::connection(self::CONNECTION)->hasColumn('menus', 'permission_id'));
        $this->assertSame(2, $database->table('menu_permission')->where('menu_id', $menuId)->count());
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

    private function pivotTableMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_07_25_060850_create_menu_permission_table.php');

        return $migration;
    }

    private function pivotBackfillMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_07_25_060857_backfill_menu_permissions_to_pivot.php');

        return $migration;
    }

    private function removeScalarPermissionMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_07_25_060904_remove_permission_id_from_menus_table.php');

        return $migration;
    }
}

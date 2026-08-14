<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class SeedIdentityMigrationTest extends TestCase
{
    private const CONNECTION = 'seed-identity-migration-test';

    /** @var array<string, string> */
    private const SEED_KEYS_BY_CANONICAL_CODE = [
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

    private string $databasePath;

    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $databasePath = tempnam(sys_get_temp_dir(), 'admin9-seed-identity-migration-');

        if ($databasePath === false) {
            throw new RuntimeException('Unable to create a temporary SQLite database.');
        }

        $this->databasePath = $databasePath;
        $this->originalDefaultConnection = (string) config('database.default');

        config([
            'database.default' => self::CONNECTION,
            'database.connections.'.self::CONNECTION => array_replace(
                config('database.connections.sqlite'),
                ['database' => $this->databasePath, 'foreign_key_constraints' => true],
            ),
        ]);

        DB::purge(self::CONNECTION);
        Schema::connection(self::CONNECTION)->create('menus', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('icon', 100)->nullable();
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

    public function test_upgrade_backfills_only_canonical_codes_enforces_uniqueness_and_rolls_back_schema(): void
    {
        $database = DB::connection(self::CONNECTION);
        $database->table('menus')->insert(
            collect(array_keys(self::SEED_KEYS_BY_CANONICAL_CODE))
                ->map(fn (string $code): array => ['code' => $code])
                ->push(['code' => 'custom.menu'])
                ->all(),
        );

        $migration = require database_path('migrations/2026_08_14_163241_add_seed_identity_to_menus_table.php');
        $migration->up();

        $actualSeedKeys = $database->table('menus')
            ->whereNotNull('seed_key')
            ->orderBy('code')
            ->pluck('seed_key', 'code')
            ->all();
        $expectedSeedKeys = self::SEED_KEYS_BY_CANONICAL_CODE;
        ksort($expectedSeedKeys);

        $this->assertSame($expectedSeedKeys, $actualSeedKeys);
        $this->assertNull($database->table('menus')->where('code', 'custom.menu')->value('seed_key'));
        $this->assertTrue(Schema::connection(self::CONNECTION)->hasTable('menu_seed_tombstones'));

        try {
            $database->table('menus')->where('code', 'custom.menu')->update([
                'seed_key' => 'admin9.core.system',
            ]);
            $this->fail('Expected duplicate seed_key assignment to fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('unique', strtolower($exception->getMessage()));
        }

        $migration->down();

        $this->assertFalse(Schema::connection(self::CONNECTION)->hasTable('menu_seed_tombstones'));
        $this->assertFalse(Schema::connection(self::CONNECTION)->hasColumn('menus', 'seed_key'));
    }

    public function test_rollback_refuses_to_discard_seeded_menu_deletion_intent(): void
    {
        $database = DB::connection(self::CONNECTION);
        $database->table('menus')->insert(['code' => 'system.roles']);

        $migration = require database_path('migrations/2026_08_14_163241_add_seed_identity_to_menus_table.php');
        $migration->up();
        $database->table('menu_seed_tombstones')->insert([
            'seed_key' => 'admin9.core.system.roles.create',
            'deleted_at' => now(),
        ]);

        try {
            $migration->down();
            $this->fail('Expected rollback with deletion tombstones to fail.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Cannot remove menu seed identities while deletion tombstones exist.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::connection(self::CONNECTION)->hasTable('menu_seed_tombstones'));
        $this->assertTrue(Schema::connection(self::CONNECTION)->hasColumn('menus', 'seed_key'));
        $this->assertDatabaseHas('menu_seed_tombstones', [
            'seed_key' => 'admin9.core.system.roles.create',
        ], self::CONNECTION);
    }

    public function test_rollback_refuses_to_discard_a_seeded_menu_identity_after_code_changes(): void
    {
        $database = DB::connection(self::CONNECTION);
        $database->table('menus')->insert(['code' => 'system.roles']);

        $migration = require database_path('migrations/2026_08_14_163241_add_seed_identity_to_menus_table.php');
        $migration->up();
        $database->table('menus')->where('code', 'system.roles')->update(['code' => 'system.custom-roles']);

        try {
            $migration->down();
            $this->fail('Expected rollback after a seeded menu code change to fail.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Cannot remove menu seed identities after a seeded menu code has changed.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::connection(self::CONNECTION)->hasTable('menu_seed_tombstones'));
        $this->assertTrue(Schema::connection(self::CONNECTION)->hasColumn('menus', 'seed_key'));
        $this->assertSame(
            'admin9.core.system.roles',
            $database->table('menus')->where('code', 'system.custom-roles')->value('seed_key'),
        );
    }

    public function test_legacy_roles_icon_repair_is_precise_idempotent_and_preserves_admin_edits(): void
    {
        $database = DB::connection(self::CONNECTION);
        $database->table('menus')->insert([
            ['code' => 'system.roles', 'icon' => 'team'],
            ['code' => 'system.permissions', 'icon' => 'team'],
            ['code' => 'custom.team', 'icon' => 'team'],
        ]);

        $seedIdentityMigration = require database_path('migrations/2026_08_14_163241_add_seed_identity_to_menus_table.php');
        $seedIdentityMigration->up();
        $iconRepairMigration = require database_path('migrations/2026_08_14_163242_repair_legacy_roles_menu_icon.php');

        $iconRepairMigration->up();
        $iconRepairMigration->up();

        $this->assertSame('user-group', $database->table('menus')->where('code', 'system.roles')->value('icon'));
        $this->assertSame('team', $database->table('menus')->where('code', 'system.permissions')->value('icon'));
        $this->assertSame('team', $database->table('menus')->where('code', 'custom.team')->value('icon'));

        $database->table('menus')->where('code', 'system.roles')->update(['icon' => 'book']);
        $iconRepairMigration->up();

        $this->assertSame('book', $database->table('menus')->where('code', 'system.roles')->value('icon'));
    }
}

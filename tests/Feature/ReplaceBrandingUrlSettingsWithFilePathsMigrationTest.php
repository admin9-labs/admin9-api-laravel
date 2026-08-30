<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ReplaceBrandingUrlSettingsWithFilePathsMigrationTest extends TestCase
{
    private const CONNECTION = 'replace-branding-url-settings-with-file-paths-test';

    private const NEW_KEYS = [
        'system.branding.navigation_logo',
        'system.branding.login_logo',
        'system.branding.login_background',
        'system.branding.favicon',
    ];

    private const OLD_KEYS = [
        'system.branding.navigation_logo_url',
        'system.branding.login_logo_url',
        'system.branding.login_background_url',
        'system.branding.favicon_url',
    ];

    private string $databasePath;

    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $databasePath = tempnam(sys_get_temp_dir(), 'admin9-branding-paths-');

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
        $this->createSystemConfigsTable();
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

    public function test_legacy_urls_are_discarded_and_replaced_with_empty_business_keys(): void
    {
        foreach (self::OLD_KEYS as $key) {
            $this->insertSetting($key, 'https://files.example.test/storage/legacy.png');
        }

        $this->migration()->up();

        $database = DB::connection(self::CONNECTION);
        $this->assertSame([], $database->table('system_configs')->whereIn('key', self::OLD_KEYS)->pluck('key')->all());
        $this->assertSame(self::NEW_KEYS, $database->table('system_configs')->whereIn('key', self::NEW_KEYS)->orderBy('sort')->pluck('key')->all());
        $this->assertSame([null, null, null, null], $database->table('system_configs')->whereIn('key', self::NEW_KEYS)->orderBy('sort')->pluck('value')->all());
    }

    public function test_retry_preserves_existing_file_path_values(): void
    {
        $this->migration()->up();
        DB::connection(self::CONNECTION)
            ->table('system_configs')
            ->where('key', 'system.branding.navigation_logo')
            ->update(['value' => 'files/2026/08/58918880-c212-46f0-ac99-b9dc35a92c6e.png']);
        $this->migration()->up();

        $database = DB::connection(self::CONNECTION);
        $this->assertSame('files/2026/08/58918880-c212-46f0-ac99-b9dc35a92c6e.png', $database->table('system_configs')->where('key', 'system.branding.navigation_logo')->value('value'));
        $this->assertFalse($database->table('system_configs')->whereIn('key', self::OLD_KEYS)->exists());
    }

    public function test_preexisting_business_key_conflict_fails_without_changing_existing_data(): void
    {
        $this->insertSetting('system.branding.navigation_logo', 'private-value', [
            'name' => 'Custom navigation setting',
            'description' => 'Existing private configuration',
            'is_public' => false,
        ]);
        $this->insertSetting('system.branding.navigation_logo_url', 'https://legacy.example.test/navigation.png');

        try {
            $this->migration()->up();
            $this->fail('The migration should reject pre-existing business key conflicts.');
        } catch (RuntimeException) {
            $database = DB::connection(self::CONNECTION);
            $customSetting = $database->table('system_configs')->where('key', 'system.branding.navigation_logo')->firstOrFail();

            $this->assertSame('Custom navigation setting', $customSetting->name);
            $this->assertSame('private-value', $customSetting->value);
            $this->assertFalse((bool) $customSetting->is_public);
            $this->assertTrue($database->table('system_configs')->where('key', 'system.branding.navigation_logo_url')->exists());
            $this->assertSame(1, $database->table('system_configs')->whereIn('key', self::NEW_KEYS)->count());
        }
    }

    public function test_failure_rolls_back_legacy_key_deletion(): void
    {
        $this->insertSetting('system.branding.navigation_logo_url', 'https://legacy.example.test/navigation.png');
        Schema::connection(self::CONNECTION)->table('system_configs', function (Blueprint $table): void {
            $table->dropColumn('sort');
        });

        try {
            $this->migration()->up();
            $this->fail('The migration should fail when required configuration metadata cannot be stored.');
        } catch (QueryException) {
            $database = DB::connection(self::CONNECTION);
            $this->assertTrue($database->table('system_configs')->where('key', 'system.branding.navigation_logo_url')->exists());
            $this->assertFalse($database->table('system_configs')->whereIn('key', self::NEW_KEYS)->exists());
        }
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_08_30_052648_replace_branding_url_settings_with_file_paths.php');
    }

    private function createSystemConfigsTable(): void
    {
        Schema::connection(self::CONNECTION)->create('system_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('key', 150)->unique();
            $table->text('value')->nullable();
            $table->string('type', 30)->default('string');
            $table->string('config_group', 100)->default('default');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertSetting(string $key, ?string $value, array $attributes = []): void
    {
        DB::connection(self::CONNECTION)->table('system_configs')->insert([
            'name' => $key,
            'key' => $key,
            'value' => $value,
            'type' => 'string',
            'config_group' => 'system.branding',
            'is_public' => true,
            'is_active' => true,
            'sort' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }
}

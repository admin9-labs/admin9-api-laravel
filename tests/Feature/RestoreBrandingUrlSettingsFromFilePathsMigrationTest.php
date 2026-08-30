<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class RestoreBrandingUrlSettingsFromFilePathsMigrationTest extends TestCase
{
    private const CONNECTION = 'restore-branding-url-settings-from-file-paths-test';

    private const PATH_KEYS = [
        'system.branding.navigation_logo',
        'system.branding.login_logo',
        'system.branding.login_background',
        'system.branding.favicon',
    ];

    private const URL_DEFINITIONS = [
        'system.branding.navigation_logo_url' => ['name' => '后台导航 Logo URL', 'description' => '后台导航使用的 Logo URL', 'sort' => 40],
        'system.branding.login_logo_url' => ['name' => '登录页 Logo URL', 'description' => '登录页使用的 Logo URL', 'sort' => 50],
        'system.branding.login_background_url' => ['name' => '登录页背景图 URL', 'description' => '登录页使用的背景图片 URL', 'sort' => 60],
        'system.branding.favicon_url' => ['name' => '浏览器图标 URL', 'description' => '浏览器 Favicon URL', 'sort' => 70],
    ];

    private string $databasePath;

    private string $originalDefaultConnection;

    private mixed $originalPublicUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $databasePath = tempnam(sys_get_temp_dir(), 'admin9-restore-branding-urls-');

        if ($databasePath === false) {
            throw new RuntimeException('Unable to create a temporary SQLite database.');
        }

        $this->databasePath = $databasePath;
        $this->originalDefaultConnection = (string) config('database.default');
        $this->originalPublicUrl = config('filesystems.disks.public.url');

        config([
            'database.default' => self::CONNECTION,
            'database.connections.'.self::CONNECTION => array_replace(
                config('database.connections.sqlite'),
                ['database' => $this->databasePath, 'foreign_key_constraints' => true],
            ),
            'filesystems.disks.public.url' => 'https://files.example.test/storage',
        ]);

        DB::purge(self::CONNECTION);
        $this->createSystemConfigsTable();
        $this->createFilesTable();
    }

    protected function tearDown(): void
    {
        config([
            'database.default' => $this->originalDefaultConnection,
            'filesystems.disks.public.url' => $this->originalPublicUrl,
        ]);
        DB::disconnect(self::CONNECTION);
        DB::purge(self::CONNECTION);

        if (isset($this->databasePath) && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_ready_public_image_paths_are_converted_and_unavailable_paths_are_cleared(): void
    {
        $readyPath = 'files/2026/08/58918880-c212-46f0-ac99-b9dc35a92c6e.png';
        $failedPath = 'files/2026/08/68918880-c212-46f0-ac99-b9dc35a92c6e.png';
        $this->insertFile($readyPath);
        $this->insertFile($failedPath, ['status' => 'failed']);
        $this->insertPathSettings([
            $readyPath,
            'files/2026/08/78918880-c212-46f0-ac99-b9dc35a92c6e.png',
            null,
            $failedPath,
        ]);

        $this->migration()->up();

        $database = DB::connection(self::CONNECTION);
        $this->assertSame([], $database->table('system_configs')->whereIn('key', self::PATH_KEYS)->pluck('key')->all());
        $this->assertSame(
            [
                'https://files.example.test/storage/'.$readyPath,
                null,
                null,
                null,
            ],
            $database->table('system_configs')
                ->whereIn('key', array_keys(self::URL_DEFINITIONS))
                ->orderBy('sort')
                ->pluck('value')
                ->all(),
        );
    }

    public function test_retry_preserves_existing_url_values(): void
    {
        foreach (self::URL_DEFINITIONS as $key => $definition) {
            $this->insertSetting($key, $key === 'system.branding.navigation_logo_url' ? 'https://cdn.example.test/logo.png' : null, $definition);
        }

        $this->migration()->up();

        $this->assertSame(
            'https://cdn.example.test/logo.png',
            DB::connection(self::CONNECTION)
                ->table('system_configs')
                ->where('key', 'system.branding.navigation_logo_url')
                ->value('value'),
        );
    }

    public function test_mixed_path_and_url_settings_fail_without_changing_data(): void
    {
        $this->insertPathSettings([null, null, null, null]);
        $definition = self::URL_DEFINITIONS['system.branding.navigation_logo_url'];
        $this->insertSetting('system.branding.navigation_logo_url', 'https://cdn.example.test/logo.png', $definition);

        try {
            $this->migration()->up();
            $this->fail('The migration should reject mixed branding setting contracts.');
        } catch (RuntimeException) {
            $database = DB::connection(self::CONNECTION);
            $this->assertSame(4, $database->table('system_configs')->whereIn('key', self::PATH_KEYS)->count());
            $this->assertSame(1, $database->table('system_configs')->whereIn('key', array_keys(self::URL_DEFINITIONS))->count());
        }
    }

    public function test_failure_rolls_back_url_insertion(): void
    {
        $this->insertPathSettings([null, null, null, null]);
        Schema::connection(self::CONNECTION)->table('system_configs', function (Blueprint $table): void {
            $table->dropColumn('sort');
        });

        try {
            $this->migration()->up();
            $this->fail('The migration should fail when URL setting metadata cannot be stored.');
        } catch (QueryException) {
            $database = DB::connection(self::CONNECTION);
            $this->assertSame(4, $database->table('system_configs')->whereIn('key', self::PATH_KEYS)->count());
            $this->assertFalse($database->table('system_configs')->whereIn('key', array_keys(self::URL_DEFINITIONS))->exists());
        }
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_08_30_141252_restore_branding_url_settings_from_file_paths.php');
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

    private function createFilesTable(): void
    {
        Schema::connection(self::CONNECTION)->create('files', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 64);
            $table->string('type', 16);
            $table->string('path');
            $table->string('status', 16);
            $table->uuid('deletion_token')->nullable();
            $table->unique(['disk', 'path']);
        });
    }

    /**
     * @param  array<int, ?string>  $values
     */
    private function insertPathSettings(array $values): void
    {
        foreach (self::PATH_KEYS as $index => $key) {
            $this->insertSetting($key, $values[$index], [
                'name' => $key,
                'description' => $key,
                'sort' => ($index + 4) * 10,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function insertSetting(string $key, ?string $value, array $definition): void
    {
        DB::connection(self::CONNECTION)->table('system_configs')->insert([
            ...$definition,
            'key' => $key,
            'value' => $value,
            'type' => 'string',
            'config_group' => 'system.branding',
            'is_public' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertFile(string $path, array $attributes = []): void
    {
        DB::connection(self::CONNECTION)->table('files')->insert([
            'disk' => 'public',
            'type' => 'image',
            'path' => $path,
            'status' => 'ready',
            'deletion_token' => null,
            ...$attributes,
        ]);
    }
}

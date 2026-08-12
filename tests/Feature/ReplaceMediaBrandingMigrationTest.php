<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ReplaceMediaBrandingMigrationTest extends TestCase
{
    private const CONNECTION = 'replace-media-branding-migration-test';

    private string $databasePath;

    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $databasePath = tempnam(sys_get_temp_dir(), 'admin9-branding-migration-');

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
            'filesystems.disks.public.url' => 'https://files.example.test/storage',
        ]);

        DB::purge(self::CONNECTION);
        $this->createUsersTable();
        $this->createSystemConfigsTable();
        $this->createMediaTable();
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

    public function test_valid_media_id_is_converted_and_invalid_values_are_cleared(): void
    {
        $database = DB::connection(self::CONNECTION);
        $database->table('media')->insert($this->mediaRecord(7, 'branding/logo.svg'));
        $this->insertLegacyValue('navigation_logo', '7');
        $this->insertLegacyValue('login_logo', 'missing');
        $this->insertLegacyValue('login_background', 'not-a-number');
        $this->insertLegacyValue('favicon', '999');

        $this->migration()->up();

        $this->assertSame('https://files.example.test/storage/branding/logo.svg', $this->value('navigation_logo_url'));
        $this->assertNull($this->value('login_logo_url'));
        $this->assertNull($this->value('login_background_url'));
        $this->assertNull($this->value('favicon_url'));
        $this->assertSame(0, $database->table('system_configs')->where('key', 'like', 'system.branding.%_media_id')->count());
        $this->assertFalse(Schema::connection(self::CONNECTION)->hasTable('media'));
    }

    public function test_retry_preserves_existing_url_when_legacy_key_is_already_removed(): void
    {
        $this->insertUrlValue('navigation_logo_url', 'https://already.example.test/logo.svg');

        $this->migration()->up();

        $this->assertSame('https://already.example.test/logo.svg', $this->value('navigation_logo_url'));
    }

    public function test_down_restores_media_status_with_ready_default(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertTrue(Schema::connection(self::CONNECTION)->hasColumn('media', 'status'));
        $this->assertSame('ready', DB::connection(self::CONNECTION)->table('media')->insertGetId($this->mediaRecord(null, 'rollback/file.txt'))
            ? DB::connection(self::CONNECTION)->table('media')->value('status')
            : null);
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_08_13_000000_replace_media_branding_with_urls.php');
    }

    private function createUsersTable(): void
    {
        Schema::connection(self::CONNECTION)->create('users', function (Blueprint $table): void {
            $table->id();
        });
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

    private function createMediaTable(): void
    {
        Schema::connection(self::CONNECTION)->create('media', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('disk', 64);
            $table->string('path');
            $table->string('mime_type', 64);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('status', 16)->default('ready');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('deletion_token')->nullable();
            $table->timestamp('deletion_started_at')->nullable();
            $table->timestamps();
        });
    }

    /** @return array<string, mixed> */
    private function mediaRecord(?int $id, string $path): array
    {
        return array_filter([
            'id' => $id,
            'name' => basename($path),
            'disk' => 'public',
            'path' => $path,
            'mime_type' => 'image/svg+xml',
            'extension' => 'svg',
            'size' => 10,
            'width' => null,
            'height' => null,
            'created_by' => null,
            'deletion_token' => null,
            'deletion_started_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function insertLegacyValue(string $name, string $value): void
    {
        DB::connection(self::CONNECTION)->table('system_configs')->insert([
            'name' => $name,
            'key' => "system.branding.{$name}_media_id",
            'value' => $value,
            'type' => 'integer',
            'config_group' => 'system.branding',
            'is_public' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertUrlValue(string $name, string $value): void
    {
        DB::connection(self::CONNECTION)->table('system_configs')->insert([
            'name' => $name,
            'key' => "system.branding.{$name}",
            'value' => $value,
            'type' => 'string',
            'config_group' => 'system.branding',
            'is_public' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function value(string $name): ?string
    {
        return DB::connection(self::CONNECTION)->table('system_configs')->where('key', "system.branding.{$name}")->value('value');
    }
}

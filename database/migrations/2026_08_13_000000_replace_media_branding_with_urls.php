<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    private const FIELDS = [
        'navigation_logo' => 'navigation_logo_url',
        'login_logo' => 'login_logo_url',
        'login_background' => 'login_background_url',
        'favicon' => 'favicon_url',
    ];

    public function up(): void
    {
        $this->replaceBrandingSettings();
    }

    private function replaceBrandingSettings(): void
    {
        DB::transaction(function (): void {
            $media = Schema::hasTable('media')
                ? DB::table('media')->get()->keyBy('id')
                : collect();

            foreach (self::FIELDS as $legacyName => $newName) {
                $legacyKey = "system.branding.{$legacyName}_media_id";
                $newKey = "system.branding.{$newName}";
                $definition = [
                    'navigation_logo_url' => ['name' => '后台导航 Logo URL', 'type' => 'string', 'description' => '后台导航使用的 Logo URL', 'sort' => 40],
                    'login_logo_url' => ['name' => '登录页 Logo URL', 'type' => 'string', 'description' => '登录页使用的 Logo URL', 'sort' => 50],
                    'login_background_url' => ['name' => '登录页背景图 URL', 'type' => 'string', 'description' => '登录页使用的背景图片 URL', 'sort' => 60],
                    'favicon_url' => ['name' => '浏览器图标 URL', 'type' => 'string', 'description' => '浏览器 Favicon URL', 'sort' => 70],
                ][$newName];
                $legacyValue = DB::table('system_configs')->where('key', $legacyKey)->value('value');
                $url = DB::table('system_configs')->where('key', $newKey)->value('value');

                if (is_string($legacyValue) && ctype_digit($legacyValue)) {
                    $record = $media->get((int) $legacyValue);

                    if ($record !== null) {
                        try {
                            $candidate = Storage::disk($record->disk)->url($record->path);
                            $url = filter_var($candidate, FILTER_VALIDATE_URL) ? $candidate : null;
                        } catch (Throwable) {
                            $url = null;
                        }
                    } else {
                        $url = null;
                    }
                } elseif ($legacyValue !== null) {
                    $url = null;
                }

                DB::table('system_configs')->updateOrInsert(
                    ['key' => $newKey],
                    [
                        ...$definition,
                        'config_group' => 'system.branding',
                        'is_public' => true,
                        'is_active' => true,
                        'value' => $url,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
                DB::table('system_configs')->where('key', $legacyKey)->delete();
            }

            if (Schema::hasTable('media')) {
                Schema::drop('media');
            }
        });
    }

    public function down(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->index();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('mime_type', 64);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('status', 16)->default('ready')->after('height');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('deletion_token')->nullable();
            $table->timestamp('deletion_started_at')->nullable();
            $table->timestamps();
            $table->unique(['disk', 'path']);
            $table->index(['deletion_token', 'id']);
        });
    }
};

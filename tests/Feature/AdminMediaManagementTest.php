<?php

namespace Tests\Feature;

use App\Actions\Admin\StoreMedia;
use App\Models\Media;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use stdClass;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminMediaManagementTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    private const PERMISSIONS = [
        'system.media.view',
        'system.media.create',
        'system.media.delete',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Log::setDefaultDriver('null');
    }

    #[DataProvider('imageProvider')]
    public function test_admin_can_upload_each_supported_image_type(string $filename, string $mimeType, string $extension): void
    {
        Storage::fake('public');
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $response = $this->post('/api/admin/media', [
            'file' => UploadedFile::fake()->image($filename, 32, 24),
        ], array_merge($headers, ['Accept' => 'application/json']))
            ->assertOk()
            ->assertJsonPath('data.media.mime_type', $mimeType)
            ->assertJsonPath('data.media.extension', $extension)
            ->assertJsonPath('data.media.width', 32)
            ->assertJsonPath('data.media.height', 24)
            ->assertJsonPath('data.media.status', Media::STATUS_READY)
            ->assertJsonMissingPath('data.media.disk')
            ->assertJsonMissingPath('data.media.path')
            ->assertJsonMissingPath('data.media.created_by')
            ->assertHeader('X-Request-Id');

        $media = Media::query()->findOrFail($response->json('data.media.id'));
        $this->assertStringStartsWith('media/'.now()->format('Y/m').'/', $media->path);
        $this->assertStringEndsWith('.'.$extension, $media->path);
        $this->assertStringContainsString('/storage/media/', $response->json('data.media.url'));
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_media_list_is_paginated_searchable_and_sorted_by_descending_id(): void
    {
        Storage::fake('public');
        Media::factory()->create(['name' => 'first.jpg']);
        $matching = Media::factory()->create(['name' => 'needle.png']);
        Media::factory()->create(['name' => 'last.jpg']);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $this->getJson('/api/admin/media?search=needle&per_page=1', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('meta.page_size', 1)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/admin/media?per_page=2', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'last.jpg')
            ->assertJsonPath('data.1.name', 'needle.png');
    }

    public function test_upload_rejects_mismatched_extensions_corrupt_images_svg_and_oversize_files(): void
    {
        Storage::fake('public');
        $headers = array_merge(
            $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS)),
            ['Accept' => 'application/json'],
        );
        $png = UploadedFile::fake()->image('source.png');
        $mismatched = new UploadedFile($png->getPathname(), 'disguised.jpg', 'image/png', null, true);
        $corruptPath = tempnam(sys_get_temp_dir(), 'admin9-media-corrupt-');
        $svgPath = tempnam(sys_get_temp_dir(), 'admin9-media-svg-');
        $this->assertIsString($corruptPath);
        $this->assertIsString($svgPath);
        file_put_contents($corruptPath, 'not an image');
        file_put_contents($svgPath, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        try {
            foreach ([
                $mismatched,
                new UploadedFile($corruptPath, 'corrupt.jpg', 'image/jpeg', null, true),
                new UploadedFile($svgPath, 'vector.svg', 'image/svg+xml', null, true),
                UploadedFile::fake()->image('large.png')->size(5121),
            ] as $file) {
                $response = $this->post('/api/admin/media', ['file' => $file], $headers);
                $this->assertSame(422, $response->status(), $file->getClientOriginalName());
                $response->assertJsonValidationErrors('file');
            }
        } finally {
            if (is_file($corruptPath)) {
                unlink($corruptPath);
            }

            if (is_file($svgPath)) {
                unlink($svgPath);
            }
        }

        $this->assertSame([], Storage::disk('public')->allFiles('media'));
        $this->assertSame(0, Media::query()->count());
    }

    public function test_database_failure_before_upload_never_writes_a_file(): void
    {
        Storage::fake('public');
        $headers = array_merge(
            $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS)),
            ['Accept' => 'application/json'],
        );
        Event::listen('eloquent.creating: '.Media::class, static function (): void {
            throw new RuntimeException('forced media metadata failure');
        });

        try {
            $this->post('/api/admin/media', [
                'file' => UploadedFile::fake()->image('compensate.jpg'),
            ], $headers)->assertInternalServerError();
        } finally {
            Event::forget('eloquent.creating: '.Media::class);
        }

        $this->assertSame([], Storage::disk('public')->allFiles('media'));
        $this->assertSame(0, Media::query()->count());
    }

    public function test_upload_does_not_write_after_pending_metadata_is_claimed_for_deletion(): void
    {
        Storage::fake('public');
        $deletionToken = (string) Str::uuid();
        Event::listen('eloquent.created: '.Media::class, static function (Media $media) use ($deletionToken): void {
            if ($media->status === Media::STATUS_PENDING) {
                Media::query()->whereKey($media->getKey())->update([
                    'deletion_token' => $deletionToken,
                    'deletion_started_at' => now(),
                    'created_at' => now()->subMinutes(Media::PENDING_UPLOAD_LEASE_MINUTES),
                ]);
            }
        });

        try {
            $this->app->make(StoreMedia::class)->handle(
                UploadedFile::fake()->image('claimed.jpg'),
                User::factory()->create(),
            );
            $this->fail('The upload must stop after its pending metadata is claimed for deletion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Media upload lease expired before the file was stored.', $exception->getMessage());
        } finally {
            Event::forget('eloquent.created: '.Media::class);
        }

        $media = Media::query()->firstOrFail();
        $this->assertSame(Media::STATUS_PENDING, $media->status);
        $this->assertSame($deletionToken, $media->deletion_token);
        $this->assertSame([], Storage::disk('public')->allFiles('media'));
    }

    #[DataProvider('storageFailureProvider')]
    public function test_storage_failure_removes_pending_metadata_when_no_file_remains(bool $throws): void
    {
        $this->bindStoreFilesystemFailure($throws);

        try {
            $this->app->make(StoreMedia::class)->handle(
                UploadedFile::fake()->image('failed.jpg'),
                User::factory()->create(),
            );
            $this->fail('The media store must fail.');
        } catch (RuntimeException) {
            $this->assertSame(0, Media::query()->count());
        }
    }

    public function test_failed_metadata_cleanup_leaves_a_visible_failed_record_that_delete_can_recover(): void
    {
        Storage::fake('public');
        $this->bindStoreFilesystemFailure(throws: false);
        Event::listen('eloquent.deleting: '.Media::class, static function (): void {
            throw new RuntimeException('forced pending media cleanup failure');
        });

        try {
            $this->app->make(StoreMedia::class)->handle(
                UploadedFile::fake()->image('recoverable.jpg'),
                User::factory()->create(),
            );
            $this->fail('The media store must fail.');
        } catch (RuntimeException) {
            $this->assertSame(1, Media::query()->count());
        } finally {
            Event::forget('eloquent.deleting: '.Media::class);
        }

        $media = Media::query()->firstOrFail();
        $this->assertSame(Media::STATUS_FAILED, $media->status);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));
        $this->bindMissingFileFilesystem($media);

        $this->getJson('/api/admin/media', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', $media->id)
            ->assertJsonPath('data.0.status', Media::STATUS_FAILED)
            ->assertJsonPath('data.0.url', null);
        $this->deleteJson('/api/admin/media/'.$media->id, [], $headers)->assertOk();
        $this->assertModelMissing($media);
    }

    public function test_metadata_finalization_failure_keeps_file_and_failed_record_recoverable(): void
    {
        Storage::fake('public');
        Event::listen('eloquent.updating: '.Media::class, static function (Media $media): void {
            if ($media->isDirty('status') && $media->status === Media::STATUS_READY) {
                throw new RuntimeException('forced media finalization failure');
            }
        });

        try {
            $this->app->make(StoreMedia::class)->handle(
                UploadedFile::fake()->image('finalize.jpg'),
                User::factory()->create(),
            );
            $this->fail('The media finalization must fail.');
        } catch (RuntimeException) {
            $this->assertSame(1, Media::query()->count());
        } finally {
            Event::forget('eloquent.updating: '.Media::class);
        }

        $media = Media::query()->firstOrFail();
        $this->assertSame(Media::STATUS_FAILED, $media->status);
        Storage::disk('public')->assertExists($media->path);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $this->deleteJson('/api/admin/media/'.$media->id, [], $headers)->assertOk();
        Storage::disk('public')->assertMissing($media->path);
        $this->assertModelMissing($media);
    }

    public function test_structural_inspection_failure_never_writes_a_file_or_metadata(): void
    {
        Storage::fake('public');
        $corruptPath = tempnam(sys_get_temp_dir(), 'admin9-media-structure-');
        $this->assertIsString($corruptPath);
        file_put_contents($corruptPath, 'not an image');
        $file = new class($corruptPath, 'corrupt.jpg', 'image/jpeg', null, true) extends UploadedFile
        {
            public function getMimeType(): ?string
            {
                return 'image/jpeg';
            }
        };

        try {
            $this->app->make(StoreMedia::class)->handle($file, User::factory()->create());
            $this->fail('Invalid image structure should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        } finally {
            if (is_file($corruptPath)) {
                unlink($corruptPath);
            }
        }

        $this->assertSame([], Storage::disk('public')->allFiles('media'));
        $this->assertSame(0, Media::query()->count());
    }

    public function test_delete_removes_file_and_metadata_and_missing_file_is_idempotent(): void
    {
        Storage::fake('public');
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));
        $stored = $this->post('/api/admin/media', [
            'file' => UploadedFile::fake()->image('delete.jpg'),
        ], array_merge($headers, ['Accept' => 'application/json']))->assertOk();
        $media = Media::query()->findOrFail($stored->json('data.media.id'));

        $this->deleteJson('/api/admin/media/'.$media->id, [], $headers)->assertOk();
        Storage::disk('public')->assertMissing($media->path);
        $this->assertModelMissing($media);

        $missing = Media::factory()->create(['disk' => 'public']);
        $this->deleteJson('/api/admin/media/'.$missing->id, [], $headers)->assertOk();
        $this->assertModelMissing($missing);

        $events = Activity::query()->whereIn('event', ['media_uploaded', 'media_deleted'])->pluck('event');
        $this->assertContains('media_uploaded', $events);
        $this->assertContains('media_deleted', $events);
        $mediaPath = $media->path;
        Activity::query()->whereIn('event', ['media_uploaded', 'media_deleted'])->each(function (Activity $activity) use ($mediaPath): void {
            $this->assertStringNotContainsString('/private/', $activity->properties->toJson());
            $this->assertStringNotContainsString($mediaPath, $activity->properties->toJson());
        });
    }

    public function test_delete_false_returns_stable_503_and_retains_metadata(): void
    {
        $media = Media::factory()->create(['disk' => 'public']);
        $this->bindFailingFilesystem($media, returnsFalse: true);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $response = $this->deleteJson('/api/admin/media/'.$media->id, [], $headers);
        $this->assertMediaDeleteFailure($response->getContent(), $response->status(), $response->headers->get('X-Request-Id'));
        $this->assertModelExists($media);
        $this->assertNull($media->refresh()->deletion_token);
    }

    public function test_delete_exception_returns_stable_503_and_retains_metadata(): void
    {
        $media = Media::factory()->create(['disk' => 'public']);
        $this->bindFailingFilesystem($media, returnsFalse: false);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $response = $this->deleteJson('/api/admin/media/'.$media->id, [], $headers);
        $this->assertMediaDeleteFailure($response->getContent(), $response->status(), $response->headers->get('X-Request-Id'));
        $this->assertModelExists($media);
        $this->assertNull($media->refresh()->deletion_token);
    }

    public function test_invalid_media_disk_returns_stable_503_without_claiming_deletion(): void
    {
        $media = Media::factory()->create(['disk' => 'missing-media-disk']);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $response = $this->deleteJson('/api/admin/media/'.$media->id, [], $headers);
        $this->assertMediaDeleteFailure($response->getContent(), $response->status(), $response->headers->get('X-Request-Id'));
        $this->assertNull($media->refresh()->deletion_token);
    }

    #[DataProvider('filePresenceProvider')]
    public function test_fresh_pending_upload_cannot_be_deleted(bool $fileExists): void
    {
        Storage::fake('public');
        $media = Media::factory()->create([
            'disk' => 'public',
            'status' => Media::STATUS_PENDING,
        ]);

        if ($fileExists) {
            Storage::disk('public')->put($media->path, 'image bytes');
        }

        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $this->getJson('/api/admin/media', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.url', null);

        $response = $this->deleteJson('/api/admin/media/'.$media->id, [], $headers);
        $this->assertMediaDeleteFailure($response->getContent(), $response->status(), $response->headers->get('X-Request-Id'));
        $this->assertModelExists($media);
        $this->assertNull($media->refresh()->deletion_token);

        if ($fileExists) {
            Storage::disk('public')->assertExists($media->path);
        } else {
            Storage::disk('public')->assertMissing($media->path);
        }
    }

    #[DataProvider('stalePendingProvider')]
    public function test_stale_pending_upload_can_be_recovered(bool $fileExists, bool $hasExpiredDeletionClaim): void
    {
        Storage::fake('public');
        $media = Media::factory()->create([
            'disk' => 'public',
            'status' => Media::STATUS_PENDING,
            'deletion_token' => $hasExpiredDeletionClaim ? (string) Str::uuid() : null,
            'deletion_started_at' => $hasExpiredDeletionClaim ? now() : null,
        ]);

        if ($fileExists) {
            Storage::disk('public')->put($media->path, 'image bytes');
        }

        $this->travel(Media::PENDING_UPLOAD_LEASE_MINUTES + 1)->minutes();
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $this->deleteJson('/api/admin/media/'.$media->id, [], $headers)->assertOk();

        Storage::disk('public')->assertMissing($media->path);
        $this->assertModelMissing($media);
    }

    public function test_pending_upload_lease_expires_at_the_fixed_boundary(): void
    {
        Storage::fake('public');
        $media = Media::factory()->create([
            'disk' => 'public',
            'status' => Media::STATUS_PENDING,
        ]);
        $this->travel(Media::PENDING_UPLOAD_LEASE_MINUTES)->minutes();
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $this->deleteJson('/api/admin/media/'.$media->id, [], $headers)->assertOk();

        $this->assertModelMissing($media);
    }

    public function test_stale_pending_upload_with_an_active_missing_file_claim_can_be_recovered(): void
    {
        Storage::fake('public');
        $media = Media::factory()->create([
            'disk' => 'public',
            'status' => Media::STATUS_PENDING,
        ]);
        $this->travel(Media::PENDING_UPLOAD_LEASE_MINUTES + 1)->minutes();
        $media->forceFill([
            'deletion_token' => (string) Str::uuid(),
            'deletion_started_at' => now(),
        ])->save();
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $this->deleteJson('/api/admin/media/'.$media->id, [], $headers)->assertOk();

        Storage::disk('public')->assertMissing($media->path);
        $this->assertModelMissing($media);
    }

    public function test_active_delete_owner_is_not_replaced_while_the_file_still_exists(): void
    {
        Storage::fake('public');
        $activeOwnerToken = (string) Str::uuid();
        $media = Media::factory()->create([
            'disk' => 'public',
            'deletion_token' => $activeOwnerToken,
            'deletion_started_at' => now(),
        ]);
        Storage::disk('public')->put($media->path, 'image bytes');
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $response = $this->deleteJson('/api/admin/media/'.$media->id, [], $headers);
        $this->assertMediaDeleteFailure($response->getContent(), $response->status(), $response->headers->get('X-Request-Id'));
        $this->assertSame($activeOwnerToken, $media->refresh()->deletion_token);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_expired_delete_claim_can_be_recovered(): void
    {
        Storage::fake('public');
        $media = Media::factory()->create([
            'disk' => 'public',
            'deletion_token' => (string) Str::uuid(),
            'deletion_started_at' => now()->subMinutes(6),
        ]);
        Storage::disk('public')->put($media->path, 'image bytes');
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $this->deleteJson('/api/admin/media/'.$media->id, [], $headers)->assertOk();
        Storage::disk('public')->assertMissing($media->path);
        $this->assertModelMissing($media);
    }

    public function test_delete_false_is_successful_when_a_concurrent_attempt_already_removed_the_file(): void
    {
        $media = Media::factory()->create(['disk' => 'public']);
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->twice()->with($media->path)->andReturn(true, false);
        $filesystem->shouldReceive('delete')->once()->with($media->path)->andReturn(false);
        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->once()->with('public')->andReturn($filesystem);
        $this->app->instance(FilesystemFactory::class, $factory);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $this->deleteJson('/api/admin/media/'.$media->id, [], $headers)->assertOk();
        $this->assertModelMissing($media);
    }

    public function test_old_delete_attempt_cannot_restore_a_new_owner_token(): void
    {
        $media = Media::factory()->create(['disk' => 'public']);
        $newOwnerToken = (string) Str::uuid();
        $this->bindInterleavedFilesystem($media, $newOwnerToken, throws: true);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $response = $this->deleteJson('/api/admin/media/'.$media->id, [], $headers);
        $this->assertMediaDeleteFailure($response->getContent(), $response->status(), $response->headers->get('X-Request-Id'));
        $this->assertSame($newOwnerToken, $media->refresh()->deletion_token);
    }

    public function test_old_delete_attempt_cannot_finalize_a_new_owner_token(): void
    {
        $media = Media::factory()->create(['disk' => 'public']);
        $newOwnerToken = (string) Str::uuid();
        $this->bindInterleavedFilesystem($media, $newOwnerToken, throws: false);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $response = $this->deleteJson('/api/admin/media/'.$media->id, [], $headers);
        $this->assertMediaDeleteFailure($response->getContent(), $response->status(), $response->headers->get('X-Request-Id'));
        $this->assertModelExists($media);
        $this->assertSame($newOwnerToken, $media->refresh()->deletion_token);
    }

    public function test_database_failure_after_file_delete_leaves_a_hidden_retriable_tombstone(): void
    {
        Storage::fake('public');
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));
        $media = Media::factory()->create(['disk' => 'public']);
        Storage::disk('public')->put($media->path, 'image bytes');
        Event::listen('eloquent.deleting: '.Media::class, static function (): void {
            throw new RuntimeException('forced media metadata delete failure');
        });

        try {
            $this->deleteJson('/api/admin/media/'.$media->id, [], $headers)->assertInternalServerError();
        } finally {
            Event::forget('eloquent.deleting: '.Media::class);
        }

        Storage::disk('public')->assertMissing($media->path);
        $this->assertNotNull($media->refresh()->deletion_token);
        $this->getJson('/api/admin/media', $headers)
            ->assertOk()
            ->assertJsonMissing(['id' => $media->id]);

        $this->deleteJson('/api/admin/media/'.$media->id, [], $headers)->assertOk();
        $this->assertModelMissing($media);
    }

    public function test_each_media_operation_requires_its_exact_permission(): void
    {
        $media = Media::factory()->create();
        $user = User::factory()->create();
        $headers = $this->authorizationHeader($this->adminTokenFor($user));
        $cases = [
            ['GET', '/api/admin/media', [], 'system.media.create'],
            ['POST', '/api/admin/media', [], 'system.media.view'],
            ['DELETE', '/api/admin/media/'.$media->id, [], 'system.media.create'],
        ];

        foreach ($cases as [$method, $uri, $payload, $wrongPermission]) {
            $user->syncPermissions([$this->createPermission($wrongPermission)]);
            $this->json($method, $uri, $payload, $headers)->assertForbidden();
        }
    }

    public function test_media_upload_has_an_authenticated_admin_rate_limit_with_stable_contract(): void
    {
        $headers = $this->authorizationHeader($this->managerTokenFor(['system.media.create']));

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/admin/media', [], $headers)
                ->assertUnprocessable()
                ->assertHeader('X-RateLimit-Limit', '10')
                ->assertHeader('X-RateLimit-Remaining', (string) (10 - $attempt));
        }

        $response = $this->postJson('/api/admin/media', [], $headers)
            ->assertTooManyRequests()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset')
            ->assertHeader('X-Request-Id');

        $payload = json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $payload);
        $this->assertInstanceOf(stdClass::class, $payload->data);
        $this->assertInstanceOf(stdClass::class, $payload->errors);
        $this->assertSame($payload->request_id, $response->headers->get('X-Request-Id'));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function imageProvider(): array
    {
        return [
            'jpeg' => ['image.jpeg', 'image/jpeg', 'jpg'],
            'png' => ['image.png', 'image/png', 'png'],
            'webp' => ['image.webp', 'image/webp', 'webp'],
            'gif' => ['image.gif', 'image/gif', 'gif'],
        ];
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function storageFailureProvider(): array
    {
        return [
            'put returns false' => [false],
            'put throws' => [true],
        ];
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function filePresenceProvider(): array
    {
        return [
            'file is missing' => [false],
            'file exists' => [true],
        ];
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function stalePendingProvider(): array
    {
        return [
            'missing file without claim' => [false, false],
            'existing file without claim' => [true, false],
            'missing file with expired claim' => [false, true],
            'existing file with expired claim' => [true, true],
        ];
    }

    private function bindStoreFilesystemFailure(bool $throws): void
    {
        $filesystem = Mockery::mock(Filesystem::class);
        $put = $filesystem->shouldReceive('put')->once();

        if ($throws) {
            $put->andThrow(new RuntimeException('storage unavailable'));
        } else {
            $put->andReturn(false);
        }

        $filesystem->shouldReceive('delete')->once()->andReturn(false);
        $filesystem->shouldReceive('exists')->once()->andReturn(false);
        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->once()->with('public')->andReturn($filesystem);
        $this->app->instance(FilesystemFactory::class, $factory);
    }

    private function bindMissingFileFilesystem(Media $media): void
    {
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->once()->with($media->path)->andReturn(false);
        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->once()->with($media->disk)->andReturn($filesystem);
        $this->app->instance(FilesystemFactory::class, $factory);
    }

    private function bindFailingFilesystem(Media $media, bool $returnsFalse): void
    {
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->twice()->with($media->path)->andReturn(true);

        if ($returnsFalse) {
            $filesystem->shouldReceive('delete')->once()->with($media->path)->andReturn(false);
        } else {
            $filesystem->shouldReceive('delete')->once()->with($media->path)->andThrow(new RuntimeException('storage unavailable'));
        }

        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->once()->with('public')->andReturn($filesystem);
        $this->app->instance(FilesystemFactory::class, $factory);
    }

    private function bindInterleavedFilesystem(Media $media, string $newOwnerToken, bool $throws): void
    {
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->with($media->path)->andReturn(true);
        $delete = $filesystem->shouldReceive('delete')->once()->with($media->path);
        $interleave = static function () use ($media, $newOwnerToken, $throws): bool {
            Media::query()->whereKey($media->getKey())->update(['deletion_token' => $newOwnerToken]);

            if ($throws) {
                throw new RuntimeException('storage unavailable after ownership changed');
            }

            return true;
        };
        $delete->andReturnUsing($interleave);

        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->once()->with('public')->andReturn($filesystem);
        $this->app->instance(FilesystemFactory::class, $factory);
    }

    private function assertMediaDeleteFailure(string $content, int $status, ?string $requestId): void
    {
        $this->assertSame(503, $status);
        $payload = json_decode($content, flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $payload);
        $this->assertFalse($payload->success);
        $this->assertSame(503, $payload->code);
        $this->assertSame('media_delete_failed', $payload->error_code);
        $this->assertInstanceOf(stdClass::class, $payload->data);
        $this->assertSame([], get_object_vars($payload->data));
        $this->assertInstanceOf(stdClass::class, $payload->errors);
        $this->assertSame([], get_object_vars($payload->errors));
        $this->assertSame($payload->request_id, $requestId);
    }

    /**
     * @return array{Authorization: string}
     */
    private function authorizationHeader(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}

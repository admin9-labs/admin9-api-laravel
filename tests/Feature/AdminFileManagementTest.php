<?php

namespace Tests\Feature;

use App\Actions\Admin\StoreFile;
use App\Models\File;
use App\Models\User;
use App\Support\ApiRouting;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use stdClass;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;
use ZipArchive;

class AdminFileManagementTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    private const PERMISSIONS = [
        'system.file.view',
        'system.file.create',
        'system.file.delete',
    ];

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Log::setDefaultDriver('null');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[DataProvider('allowedFileProvider')]
    public function test_admin_can_upload_each_allowed_file_category(
        string $filename,
        string $contents,
        string $expectedType,
        string $expectedMimeType,
    ): void {
        Storage::fake('public');
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));
        $uploadedFile = $expectedType === 'image'
            ? UploadedFile::fake()->image($filename, 32, 24)
            : $this->uploadedFile($filename, $contents);

        $response = $this->post(ApiRouting::path('/admin/files'), [
            'file' => $uploadedFile,
        ], array_merge($headers, ['Accept' => 'application/json']))
            ->assertOk()
            ->assertJsonPath('data.file.type', $expectedType)
            ->assertJsonPath('data.file.mime_type', $expectedMimeType)
            ->assertJsonPath('data.file.status', File::STATUS_READY)
            ->assertJsonMissingPath('data.file.disk')
            ->assertJsonMissingPath('data.file.created_by')
            ->assertHeader('X-Request-Id');

        $file = File::query()->findOrFail($response->json('data.file.id'));
        $this->assertStringStartsWith('files/'.now()->format('Y/m').'/', $file->path);
        $this->assertSame($file->path, $response->json('data.file.path'));
        $this->assertStringContainsString('/storage/files/', $response->json('data.file.url'));
        Storage::disk('public')->assertExists($file->path);

        if ($expectedType === 'image') {
            $response->assertJsonPath('data.file.width', 32)->assertJsonPath('data.file.height', 24);
        } else {
            $response->assertJsonPath('data.file.width', null)->assertJsonPath('data.file.height', null);
        }
    }

    public function test_file_list_is_paginated_searchable_type_filterable_and_sorted(): void
    {
        File::factory()->create(['name' => 'first.pdf', 'type' => 'document']);
        $matching = File::factory()->create(['name' => 'needle.pdf', 'type' => 'document']);
        File::factory()->create(['name' => 'needle.png', 'type' => 'image']);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $this->getJson(ApiRouting::path('/admin/files?search=needle&type=document&per_page=1'), $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('meta.page_size', 1)
            ->assertJsonPath('meta.total', 1);

        $this->getJson(ApiRouting::path('/admin/files?per_page=2'), $headers)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'needle.png')
            ->assertJsonPath('data.1.name', 'needle.pdf');

        $this->getJson(ApiRouting::path('/admin/files?type=executable'), $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_upload_rejects_mismatch_damage_svg_unknown_and_oversize(): void
    {
        Storage::fake('public');
        $headers = array_merge(
            $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS)),
            ['Accept' => 'application/json'],
        );
        $png = UploadedFile::fake()->image('source.png');
        $mismatched = new UploadedFile($png->getPathname(), 'disguised.jpg', null, null, true);

        foreach ([
            $mismatched,
            $this->uploadedFile('corrupt.pdf', '%PDF-1.4 broken'),
            $this->uploadedFile('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            $this->uploadedFile('program.php', '<?php echo 1;'),
            UploadedFile::fake()->image('large.png')->size(5121),
        ] as $file) {
            $response = $this->post(ApiRouting::path('/admin/files'), ['file' => $file], $headers);
            $this->assertSame(422, $response->status(), $file->getClientOriginalName());
            $response->assertJsonValidationErrors('file');
        }

        $this->assertSame([], Storage::disk('public')->allFiles('files'));
        $this->assertSame(0, File::query()->count());
    }

    public function test_upload_rejects_client_supplied_type(): void
    {
        Storage::fake('public');
        $headers = array_merge(
            $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS)),
            ['Accept' => 'application/json'],
        );

        $this->post(ApiRouting::path('/admin/files'), [
            'file' => UploadedFile::fake()->image('server-classified.png'),
            'type' => 'document',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->assertSame(0, File::query()->count());
    }

    #[DataProvider('nonImageOversizeProvider')]
    public function test_each_non_image_category_enforces_its_configured_size_limit(
        string $filename,
        string $contents,
        int $sizeInKilobytes,
    ): void {
        Storage::fake('public');
        $headers = array_merge(
            $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS)),
            ['Accept' => 'application/json'],
        );
        $file = $this->uploadedFile($filename, $contents, $sizeInKilobytes * 1024);

        $this->post(ApiRouting::path('/admin/files'), ['file' => $file], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, File::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles('files'));
    }

    #[DataProvider('storageFailureProvider')]
    public function test_storage_failure_compensates_pending_metadata(bool $throws): void
    {
        $this->bindStoreFilesystemFailure($throws);

        try {
            $this->app->make(StoreFile::class)->handle(
                UploadedFile::fake()->image('failed.jpg'),
                User::factory()->create(),
            );
            $this->fail('The file store must fail.');
        } catch (RuntimeException) {
            $this->assertSame(0, File::query()->count());
        }
    }

    public function test_failed_metadata_cleanup_leaves_recoverable_failed_record(): void
    {
        Storage::fake('public');
        $this->bindStoreFilesystemFailure(throws: false);
        Event::listen('eloquent.deleting: '.File::class, static function (): void {
            throw new RuntimeException('forced file metadata cleanup failure');
        });

        try {
            $this->app->make(StoreFile::class)->handle(
                UploadedFile::fake()->image('recoverable.jpg'),
                User::factory()->create(),
            );
            $this->fail('The file store must fail.');
        } catch (RuntimeException) {
            $this->assertSame(1, File::query()->count());
        } finally {
            Event::forget('eloquent.deleting: '.File::class);
        }

        $file = File::query()->firstOrFail();
        $this->assertSame(File::STATUS_FAILED, $file->status);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));
        $this->bindMissingFileFilesystem($file);

        $this->getJson(ApiRouting::path('/admin/files'), $headers)
            ->assertOk()
            ->assertJsonPath('data.0.status', File::STATUS_FAILED)
            ->assertJsonPath('data.0.url', null);
        $this->deleteJson(ApiRouting::path('/admin/files/').$file->id, [], $headers)->assertOk();
        $this->assertModelMissing($file);
    }

    public function test_metadata_finalization_failure_keeps_file_and_failed_record_recoverable(): void
    {
        Storage::fake('public');
        Event::listen('eloquent.updating: '.File::class, static function (File $file): void {
            if ($file->isDirty('status') && $file->status === File::STATUS_READY) {
                throw new RuntimeException('forced file finalization failure');
            }
        });

        try {
            $this->app->make(StoreFile::class)->handle(
                UploadedFile::fake()->image('finalize.jpg'),
                User::factory()->create(),
            );
            $this->fail('The file finalization must fail.');
        } catch (RuntimeException) {
            $this->assertSame(1, File::query()->count());
        } finally {
            Event::forget('eloquent.updating: '.File::class);
        }

        $file = File::query()->firstOrFail();
        $this->assertSame(File::STATUS_FAILED, $file->status);
        Storage::disk('public')->assertExists($file->path);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $this->deleteJson(ApiRouting::path('/admin/files/').$file->id, [], $headers)->assertOk();
        Storage::disk('public')->assertMissing($file->path);
        $this->assertModelMissing($file);
    }

    public function test_upload_does_not_write_after_pending_metadata_is_claimed_for_deletion(): void
    {
        Storage::fake('public');
        $deletionToken = (string) Str::uuid();
        Event::listen('eloquent.created: '.File::class, static function (File $file) use ($deletionToken): void {
            if ($file->status === File::STATUS_PENDING) {
                File::query()->whereKey($file->getKey())->update([
                    'deletion_token' => $deletionToken,
                    'deletion_started_at' => now(),
                    'created_at' => now()->subMinutes(File::PENDING_UPLOAD_LEASE_MINUTES),
                ]);
            }
        });

        try {
            $this->app->make(StoreFile::class)->handle(
                UploadedFile::fake()->image('claimed.jpg'),
                User::factory()->create(),
            );
            $this->fail('The upload must stop after its pending metadata is claimed for deletion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('File upload lease expired before the file was stored.', $exception->getMessage());
        } finally {
            Event::forget('eloquent.created: '.File::class);
        }

        $file = File::query()->firstOrFail();
        $this->assertSame(File::STATUS_PENDING, $file->status);
        $this->assertSame($deletionToken, $file->deletion_token);
        $this->assertSame([], Storage::disk('public')->allFiles('files'));
    }

    public function test_delete_removes_file_and_metadata_and_records_audit_events(): void
    {
        Storage::fake('public');
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));
        $stored = $this->post(ApiRouting::path('/admin/files'), [
            'file' => UploadedFile::fake()->image('delete.jpg'),
        ], array_merge($headers, ['Accept' => 'application/json']))->assertOk();
        $file = File::query()->findOrFail($stored->json('data.file.id'));

        $this->deleteJson(ApiRouting::path('/admin/files/').$file->id, [], $headers)->assertOk();
        Storage::disk('public')->assertMissing($file->path);
        $this->assertModelMissing($file);

        $events = Activity::query()->whereIn('event', ['file_uploaded', 'file_deleted'])->pluck('event');
        $this->assertContains('file_uploaded', $events);
        $this->assertContains('file_deleted', $events);
    }

    public function test_delete_failure_returns_stable_error_and_retains_metadata(): void
    {
        $file = File::factory()->create(['disk' => 'public']);
        $this->bindFailingFilesystem($file);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $response = $this->deleteJson(ApiRouting::path('/admin/files/').$file->id, [], $headers)
            ->assertServiceUnavailable()
            ->assertJsonPath('error_code', 'file_delete_failed')
            ->assertHeader('X-Request-Id');

        $payload = json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $payload->data);
        $this->assertInstanceOf(stdClass::class, $payload->errors);
        $this->assertModelExists($file);
        $this->assertNull($file->refresh()->deletion_token);
    }

    public function test_pending_upload_and_active_delete_owner_are_protected(): void
    {
        Storage::fake('public');
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));
        $pending = File::factory()->create(['status' => File::STATUS_PENDING]);

        $this->deleteJson(ApiRouting::path('/admin/files/').$pending->id, [], $headers)
            ->assertServiceUnavailable()
            ->assertJsonPath('error_code', 'file_delete_failed');
        $this->assertModelExists($pending);

        $ownerToken = (string) Str::uuid();
        $claimed = File::factory()->create([
            'deletion_token' => $ownerToken,
            'deletion_started_at' => now(),
        ]);
        Storage::disk('public')->put($claimed->path, 'bytes');

        $this->deleteJson(ApiRouting::path('/admin/files/').$claimed->id, [], $headers)
            ->assertServiceUnavailable()
            ->assertJsonPath('error_code', 'file_delete_failed');
        $this->assertSame($ownerToken, $claimed->refresh()->deletion_token);
        Storage::disk('public')->assertExists($claimed->path);
    }

    public function test_old_delete_attempt_cannot_finalize_a_new_owner_token(): void
    {
        $file = File::factory()->create(['disk' => 'public']);
        $newOwnerToken = (string) Str::uuid();
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->with($file->path)->andReturn(true);
        $filesystem->shouldReceive('delete')->once()->with($file->path)->andReturnUsing(
            static function () use ($file, $newOwnerToken): bool {
                File::query()->whereKey($file->getKey())->update(['deletion_token' => $newOwnerToken]);

                return true;
            },
        );
        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->once()->with('public')->andReturn($filesystem);
        $this->app->instance(FilesystemFactory::class, $factory);
        $headers = $this->authorizationHeader($this->managerTokenFor(self::PERMISSIONS));

        $this->deleteJson(ApiRouting::path('/admin/files/').$file->id, [], $headers)
            ->assertServiceUnavailable()
            ->assertJsonPath('error_code', 'file_delete_failed');
        $this->assertModelExists($file);
        $this->assertSame($newOwnerToken, $file->refresh()->deletion_token);
    }

    public function test_each_file_operation_requires_its_exact_permission(): void
    {
        $file = File::factory()->create();
        $user = User::factory()->create();
        $headers = $this->authorizationHeader($this->adminTokenFor($user));
        $cases = [
            ['GET', ApiRouting::path('/admin/files'), [], 'system.file.create'],
            ['POST', ApiRouting::path('/admin/files'), [], 'system.file.view'],
            ['DELETE', ApiRouting::path('/admin/files/').$file->id, [], 'system.file.create'],
        ];

        foreach ($cases as [$method, $uri, $payload, $wrongPermission]) {
            $user->syncPermissions([$this->createPermission($wrongPermission)]);
            $this->json($method, $uri, $payload, $headers)->assertForbidden();
        }
    }

    public function test_file_upload_has_authenticated_admin_rate_limit(): void
    {
        $headers = $this->authorizationHeader($this->managerTokenFor(['system.file.create']));

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson(ApiRouting::path('/admin/files'), [], $headers)
                ->assertUnprocessable()
                ->assertHeader('X-RateLimit-Limit', '10')
                ->assertHeader('X-RateLimit-Remaining', (string) (10 - $attempt));
        }

        $this->postJson(ApiRouting::path('/admin/files'), [], $headers)
            ->assertTooManyRequests()
            ->assertJsonPath('code', 429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-Request-Id');
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function allowedFileProvider(): array
    {
        return [
            'image' => ['image.png', '', 'image', 'image/png'],
            'document' => ['document.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n", 'document', 'application/pdf'],
            'video' => ['video.mp4', "\0\0\0\x18ftypisom\0\0\0\0isommp41", 'video', 'video/mp4'],
            'audio' => ['audio.wav', "RIFF\x24\0\0\0WAVEfmt \x10\0\0\0\x01\0\x01\0\x40\x1f\0\0\x80\x3e\0\0\x02\0\x10\0data\0\0\0\0", 'audio', 'audio/x-wav'],
            'other' => ['archive.zip', self::ZIP_PLACEHOLDER, 'other', 'application/zip'],
        ];
    }

    private const ZIP_PLACEHOLDER = '__GENERATE_ZIP__';

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
     * @return array<string, array{string, string, int}>
     */
    public static function nonImageOversizeProvider(): array
    {
        return [
            'document' => ['large.pdf', "%PDF-1.4\n%%EOF\n", 20 * 1024 + 1],
            'audio' => ['large.wav', "RIFF\x24\0\0\0WAVEfmt \x10\0\0\0", 20 * 1024 + 1],
            'video' => ['large.mp4', "\0\0\0\x18ftypisom", 100 * 1024 + 1],
            'other' => ['large.zip', self::ZIP_PLACEHOLDER, 20 * 1024 + 1],
        ];
    }

    private function uploadedFile(string $filename, string $contents, ?int $size = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'admin9-file-');
        $this->assertIsString($path);
        $this->temporaryFiles[] = $path;

        if ($contents === self::ZIP_PLACEHOLDER) {
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $this->assertTrue($archive->addFromString('readme.txt', 'allowed archive'));
            $archive->close();
        } else {
            file_put_contents($path, $contents);
        }

        if ($size !== null) {
            $stream = fopen($path, 'ab');
            $this->assertIsResource($stream);
            $this->assertTrue(ftruncate($stream, $size));
            fclose($stream);
        }

        return new UploadedFile($path, $filename, null, null, true);
    }

    private function bindStoreFilesystemFailure(bool $throws): void
    {
        $filesystem = Mockery::mock(Filesystem::class);
        $put = $filesystem->shouldReceive('writeStream')->once();

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

    private function bindMissingFileFilesystem(File $file): void
    {
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->once()->with($file->path)->andReturn(false);
        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->once()->with($file->disk)->andReturn($filesystem);
        $this->app->instance(FilesystemFactory::class, $factory);
    }

    private function bindFailingFilesystem(File $file): void
    {
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->twice()->with($file->path)->andReturn(true);
        $filesystem->shouldReceive('delete')->once()->with($file->path)->andReturn(false);
        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->once()->with('public')->andReturn($filesystem);
        $this->app->instance(FilesystemFactory::class, $factory);
    }

    /**
     * @return array{Authorization: string}
     */
    private function authorizationHeader(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}

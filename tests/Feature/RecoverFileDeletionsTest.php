<?php

namespace Tests\Feature;

use App\Actions\Admin\DeleteFile;
use App\Models\File;
use App\Models\User;
use App\Support\ApiRouting;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class RecoverFileDeletionsTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    public function test_interrupted_metadata_deletion_preserves_the_request_for_background_recovery(): void
    {
        $this->freezeTime();
        Storage::fake('public');
        $actor = User::factory()->create();
        $file = File::factory()->create();
        Storage::disk('public')->put($file->path, 'bytes');
        Event::listen('eloquent.deleting: '.File::class, static function (): void {
            throw new RuntimeException('Metadata deletion interrupted.');
        });
        try {
            app(DeleteFile::class)->handle($file, $actor);
            $this->fail('Metadata deletion should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Metadata deletion interrupted.', $exception->getMessage());
        } finally {
            Event::forget('eloquent.deleting: '.File::class);
        }
        $this->assertSame($actor->id, $file->refresh()->deletion_requested_by);
        $this->assertNotNull($file->deletion_requested_at);
        Storage::disk('public')->assertMissing($file->path);
        $this->assertSame(0, Activity::query()->where('event', 'file_deleted')->count());

        $this->travel(6)->minutes();
        $this->artisan('files:recover-deletions')->assertSuccessful();
        $this->assertModelMissing($file);
        $audit = Activity::query()->where('event', 'file_deleted')->sole();
        $this->assertSame($actor->id, $audit->causer_id);
        $this->assertSame($actor->id, $audit->properties['deletion_requested_by']);
        $this->assertTrue($audit->properties['recovered']);
    }

    public function test_expired_requests_remove_remaining_bytes_and_repeated_recovery_is_idempotent(): void
    {
        $this->freezeTime();
        Storage::fake('public');
        $actor = User::factory()->create();
        $file = File::factory()->create([
            'deletion_token' => (string) Str::uuid(), 'deletion_started_at' => now()->subMinutes(6),
            'deletion_requested_by' => $actor->id, 'deletion_requested_at' => now()->subMinutes(6),
        ]);
        Storage::disk('public')->put($file->path, 'remaining bytes');
        $headers = ['Authorization' => 'Bearer '.$this->managerTokenFor(['system.file.view'])];
        $this->getJson(ApiRouting::path('/admin/files'), $headers)->assertOk()
            ->assertJsonPath('data.0.id', $file->id)->assertJsonPath('data.0.url', null);

        $this->artisan('files:recover-deletions')->assertSuccessful();
        $this->artisan('files:recover-deletions')->assertSuccessful();
        $this->assertModelMissing($file);
        Storage::disk('public')->assertMissing($file->path);
        $this->assertSame(1, Activity::query()->where('event', 'file_deleted')->count());
    }

    public function test_recovery_respects_live_claims_upload_leases_and_the_attempt_limit(): void
    {
        $this->freezeTime();
        Storage::fake('public');
        $actor = User::factory()->create();
        $attributes = [
            'deletion_token' => (string) Str::uuid(), 'deletion_started_at' => now()->subMinutes(6),
            'deletion_requested_by' => $actor->id, 'deletion_requested_at' => now()->subMinutes(6),
        ];
        $live = File::factory()->create(array_replace($attributes, ['deletion_started_at' => now()]));
        $pending = File::factory()->create(array_replace($attributes, ['status' => File::STATUS_PENDING]));
        $first = File::factory()->create($attributes);
        $second = File::factory()->create($attributes);

        $this->artisan('files:recover-deletions', ['--limit' => 2])->assertSuccessful();
        $this->assertModelExists($live);
        $this->assertModelExists($pending);
        $this->assertSame($attributes['deletion_token'], $pending->refresh()->deletion_token);
        $this->assertModelMissing($first);
        $this->assertModelExists($second);
    }

    public function test_disabled_or_deleted_requesters_do_not_cancel_committed_deletion_requests(): void
    {
        $this->freezeTime();
        Storage::fake('public');
        $deletedActor = User::factory()->create();
        $disabledActor = User::factory()->create(['is_active' => false]);
        $files = collect([$deletedActor, $disabledActor])->map(fn (User $actor): File => File::factory()->create([
            'deletion_token' => (string) Str::uuid(), 'deletion_started_at' => now()->subMinutes(6),
            'deletion_requested_by' => $actor->id, 'deletion_requested_at' => now()->subMinutes(6),
        ]));
        $deletedActor->delete();

        $this->artisan('files:recover-deletions')->assertSuccessful();
        foreach ($files as $file) {
            $this->assertModelMissing($file);
        }
        $audit = Activity::query()->where('event', 'file_deleted')->where('subject_id', $files[0]->id)->sole();
        $this->assertNull($audit->causer_id);
        $this->assertSame($deletedActor->id, $audit->properties['deletion_requested_by']);
    }

    public function test_unattributed_legacy_claims_require_manual_retry_without_blocking_other_requests(): void
    {
        $this->freezeTime();
        Storage::fake('public');
        $legacy = File::factory()->create([
            'deletion_token' => (string) Str::uuid(), 'deletion_started_at' => now()->subMinutes(6),
        ]);
        $file = File::factory()->create([
            'deletion_token' => (string) Str::uuid(), 'deletion_started_at' => null,
            'deletion_requested_by' => User::factory()->create()->id, 'deletion_requested_at' => now()->subMinutes(6),
        ]);

        $this->artisan('files:recover-deletions')->assertFailed();
        $this->assertModelExists($legacy);
        $this->assertModelMissing($file);
    }

    public function test_storage_failure_preserves_a_recovery_request_until_a_later_attempt_succeeds(): void
    {
        $this->freezeTime();
        Storage::fake('public');
        $file = File::factory()->create([
            'deletion_token' => (string) Str::uuid(), 'deletion_started_at' => now()->subMinutes(6),
            'deletion_requested_by' => User::factory()->create()->id, 'deletion_requested_at' => now()->subMinutes(20),
        ]);
        Storage::disk('public')->put($file->path, 'bytes');
        $requestedAt = $file->deletion_requested_at;
        $originalFactory = app(FilesystemFactory::class);
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->with($file->path)->andReturn(true);
        $filesystem->shouldReceive('delete')->once()->with($file->path)->andReturn(false);
        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->with($file->disk)->andReturn($filesystem);
        $this->app->instance(FilesystemFactory::class, $factory);

        $this->artisan('files:recover-deletions')->assertFailed();
        $this->assertNotNull($file->refresh()->deletion_token);
        $this->assertTrue($file->deletion_requested_at->equalTo($requestedAt));
        Storage::disk('public')->assertExists($file->path);
        $this->app->instance(FilesystemFactory::class, $originalFactory);
        $this->travel(6)->minutes();

        $this->artisan('files:recover-deletions')->assertSuccessful();
        $this->assertModelMissing($file);
        Storage::disk('public')->assertMissing($file->path);
    }

    public function test_recovery_cannot_finalize_another_attempts_deletion_token(): void
    {
        $this->freezeTime();
        $file = File::factory()->create([
            'deletion_token' => (string) Str::uuid(), 'deletion_started_at' => now()->subMinutes(6),
            'deletion_requested_by' => User::factory()->create()->id,
        ]);
        $replacementToken = (string) Str::uuid();
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->once()->with($file->path)->andReturn(true);
        $filesystem->shouldReceive('delete')->once()->with($file->path)->andReturnUsing(static function () use ($file, $replacementToken): bool {
            File::query()->whereKey($file->id)->update(['deletion_token' => $replacementToken]);

            return true;
        });
        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->with($file->disk)->andReturn($filesystem);
        $this->app->instance(FilesystemFactory::class, $factory);

        $this->artisan('files:recover-deletions')->assertFailed();
        $this->assertSame($replacementToken, $file->refresh()->deletion_token);
        $this->assertSame(0, Activity::query()->where('event', 'file_deleted')->count());
    }

    public function test_invalid_attempt_limits_are_rejected_before_processing_requests(): void
    {
        foreach ([0, 1001, 'invalid'] as $limit) {
            $this->artisan('files:recover-deletions', ['--limit' => $limit])->assertFailed();
        }
    }

    public function test_upgrade_preserves_legacy_rows_without_inventing_authorized_requests(): void
    {
        $this->freezeTime();
        $migration = require database_path('migrations/2026_10_02_071252_add_deletion_request_to_files_table.php');
        $migration->down();
        $file = File::factory()->create([
            'deletion_token' => (string) Str::uuid(), 'deletion_started_at' => now()->subMinutes(6),
        ]);

        $migration->up();
        $this->assertModelExists($file);
        $this->assertNull($file->refresh()->deletion_requested_by);
        $this->assertNull($file->deletion_requested_at);
        $this->artisan('files:recover-deletions')->assertFailed();
        $this->assertModelExists($file);
    }

    public function test_storage_inspection_failure_keeps_the_request_available_for_later_recovery(): void
    {
        $this->freezeTime();
        $actor = User::factory()->create();
        $file = File::factory()->create([
            'deletion_token' => (string) Str::uuid(), 'deletion_started_at' => now()->subMinutes(6),
            'deletion_requested_by' => $actor->id, 'deletion_requested_at' => now()->subMinutes(6),
        ]);
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->once()->with($file->path)->andThrow(new RuntimeException('Storage unavailable.'));
        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->with($file->disk)->andReturn($filesystem);
        $this->app->instance(FilesystemFactory::class, $factory);

        $this->artisan('files:recover-deletions')->assertFailed();
        $this->assertModelExists($file);
        $this->assertNotNull($file->refresh()->deletion_token);
        $this->assertSame($actor->id, $file->deletion_requested_by);
        $this->assertNotNull($file->deletion_requested_at);
    }
}

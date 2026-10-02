<?php

namespace Tests\Integration\MySql;

use App\Models\File;
use App\Models\FileDirectory;
use App\Support\ApiRouting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\Support\MySqlConcurrencyDatabaseGuard;
use Tests\Support\RunsConcurrentMySqlRequests;
use Tests\TestCase;

#[Group('mysql-concurrency')]
class FileDirectoryConcurrencyTest extends TestCase
{
    use InteractsWithAdminRbac, RunsConcurrentMySqlRequests;

    protected function setUp(): void
    {
        parent::setUp();
        MySqlConcurrencyDatabaseGuard::assertSafe();
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        Storage::fake('public');
    }

    #[DataProvider('writeProvider')]
    public function test_directory_deletion_serializes_with_uploads_and_moves(string $operation): void
    {
        $token = $this->managerTokenFor(['system.file.create', 'system.file.update', 'system.file.delete']);
        for ($round = 0; $round < 3; $round++) {
            $directory = FileDirectory::factory()->create();
            $file = File::factory()->create();
            $upload = UploadedFile::fake()->image('cover.png');
            $write = $operation === 'move'
                ? ['method' => 'PUT', 'path' => '/admin/files/'.$file->id, 'payload' => ['directory_id' => $directory->id], 'token' => $token]
                : ['method' => 'POST', 'path' => '/admin/files', 'payload' => ['directory_id' => $directory->id, 'allowed_types' => ['image']], 'upload' => $upload->getPathname(), 'token' => $token];
            [$deleted, $written] = $this->raceRequests($directory, [
                ['method' => 'DELETE', 'path' => '/admin/file-directories/'.$directory->id, 'payload' => [], 'token' => $token],
                $write,
            ]);

            if ($written['status'] === 200) {
                $this->assertSame(409, $deleted['status']);
                $this->assertModelExists($directory);
                $this->assertSame(1, File::query()->where('directory_id', $directory->id)->count());
            } else {
                $this->assertContains($written['status'], [404, 422]);
                $this->assertSame(200, $deleted['status']);
                $this->assertModelMissing($directory);
                $this->assertSame(0, File::query()->where('directory_id', $directory->id)->count());
            }
        }
    }

    public static function writeProvider(): array
    {
        return [['upload'], ['move']];
    }

    public function test_url_operations_reject_collation_equivalent_but_nonidentical_paths(): void
    {
        $token = $this->managerTokenFor(['system.file.update', 'system.file.delete']);
        $headers = ['Authorization' => 'Bearer '.$token];
        $file = File::factory()->create(['path' => 'files/CaseSensitive.png']);
        Storage::disk('public')->put($file->path, 'bytes');
        $differentCase = Storage::disk('public')->url('files/casesensitive.png');
        $this->assertTrue(File::query()->where('path', 'files/casesensitive.png')->exists());

        $this->putJson(ApiRouting::path('/admin/files/by-url'), ['url' => $differentCase, 'directory_id' => null], $headers)->assertNotFound();
        $this->deleteJson(ApiRouting::path('/admin/files/by-url'), ['url' => $differentCase], $headers)->assertNotFound();
        $this->assertModelExists($file);
        Storage::disk('public')->assertExists($file->path);
        $this->deleteJson(ApiRouting::path('/admin/files/by-url'), ['url' => Storage::disk('public')->url($file->path)], $headers)->assertOk();
        $this->assertModelMissing($file);
    }
}

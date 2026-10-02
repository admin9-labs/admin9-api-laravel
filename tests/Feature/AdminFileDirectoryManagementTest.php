<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\FileDirectory;
use App\Support\ApiRouting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\TestCase;

class AdminFileDirectoryManagementTest extends TestCase
{
    use InteractsWithAdminRbac;
    use LazilyRefreshDatabase;

    private const PERMISSIONS = ['system.file.view', 'system.file.create', 'system.file.update', 'system.file.delete'];

    public function test_directories_have_two_levels_and_names_are_unique_within_each_parent(): void
    {
        $headers = $this->headersFor(self::PERMISSIONS);
        $root = $this->postJson(ApiRouting::path('/admin/file-directories'), ['name' => '  Photos  '], $headers)
            ->assertOk()->assertJsonPath('data.directory.name', 'Photos')->assertJsonPath('data.directory.parent_id', null);
        $rootId = $root->json('data.directory.id');
        $childId = $this->postJson(ApiRouting::path('/admin/file-directories'), ['name' => 'News', 'parent_id' => $rootId], $headers)
            ->assertOk()->json('data.directory.id');
        $this->postJson(ApiRouting::path('/admin/file-directories'), ['name' => 'News', 'parent_id' => $rootId], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(ApiRouting::path('/admin/file-directories'), ['name' => 'News'], $headers)->assertOk();
        $this->postJson(ApiRouting::path('/admin/file-directories'), ['name' => 'Third', 'parent_id' => $childId], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->getJson(ApiRouting::path('/admin/file-directories'), $headers)->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_nonempty_directories_are_rejected_and_empty_directories_can_be_deleted(): void
    {
        $headers = $this->headersFor(self::PERMISSIONS);
        $root = FileDirectory::factory()->create();
        $child = FileDirectory::factory()->childOf($root)->create();
        $this->deleteJson(ApiRouting::path('/admin/file-directories/'.$root->id), [], $headers)
            ->assertConflict()->assertJsonPath('error_code', 'file_directory_not_empty');
        $file = File::factory()->create(['directory_id' => $child->id]);
        $this->deleteJson(ApiRouting::path('/admin/file-directories/'.$child->id), [], $headers)->assertConflict();
        $file->delete();
        $this->deleteJson(ApiRouting::path('/admin/file-directories/'.$child->id), [], $headers)->assertOk();
        $this->deleteJson(ApiRouting::path('/admin/file-directories/'.$root->id), [], $headers)->assertOk();
    }

    public function test_directory_and_multiple_type_filters_apply_before_pagination(): void
    {
        $headers = $this->headersFor(['system.file.view']);
        $directory = FileDirectory::factory()->create();
        File::factory()->count(3)->create(['type' => 'image', 'directory_id' => $directory->id]);
        File::factory()->create(['type' => 'video', 'directory_id' => $directory->id]);
        File::factory()->create(['type' => 'audio', 'directory_id' => $directory->id]);
        $ungrouped = File::factory()->create(['type' => 'image']);
        $this->getJson(ApiRouting::path('/admin/files').'?'.http_build_query([
            'directory_id' => $directory->id, 'types' => ['image', 'video'], 'per_page' => 2, 'page' => 2,
        ]), $headers)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4)->assertJsonPath('meta.page', 2)
            ->assertJsonPath('data.0.directory_id', $directory->id);
        $this->getJson(ApiRouting::path('/admin/files').'?ungrouped=true', $headers)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ungrouped->id);
        $this->getJson(ApiRouting::path('/admin/files').'?type=image&types[]=video', $headers)->assertUnprocessable();
        $this->getJson(ApiRouting::path('/admin/files').'?ungrouped=true&directory_id='.$directory->id, $headers)->assertUnprocessable();
        $this->getJson(ApiRouting::path('/admin/files').'?types[]=archive', $headers)->assertUnprocessable();
    }

    public function test_uploads_persist_the_destination_and_enforce_allowed_types_server_side(): void
    {
        Storage::fake('public');
        $headers = $this->headersFor(['system.file.create']);
        $directory = FileDirectory::factory()->create();
        $this->post(ApiRouting::path('/admin/files'), [
            'file' => UploadedFile::fake()->image('cover.png'), 'directory_id' => $directory->id, 'allowed_types' => ['video', 'image'],
        ], $headers + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.file.directory_id', $directory->id);
        $this->post(ApiRouting::path('/admin/files'), [
            'file' => UploadedFile::fake()->image('blocked.png'), 'allowed_types' => ['video'],
        ], $headers + ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post(ApiRouting::path('/admin/files'), [
            'file' => UploadedFile::fake()->image('missing.png'), 'directory_id' => 999999,
        ], $headers + ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('directory_id');
        $this->assertDatabaseCount('files', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_files_can_move_by_id_and_exact_public_url_without_changing_their_storage_path(): void
    {
        Storage::fake('public');
        $headers = $this->headersFor(['system.file.update']);
        $directory = FileDirectory::factory()->create();
        $file = File::factory()->create();
        $url = Storage::disk('public')->url($file->path);
        $this->putJson(ApiRouting::path('/admin/files/'.$file->id), ['directory_id' => $directory->id], $headers)->assertOk();
        $this->assertSame($directory->id, $file->refresh()->directory_id);
        $this->putJson(ApiRouting::path('/admin/files/by-url'), ['url' => $url, 'directory_id' => null], $headers)->assertOk();
        $this->assertNull($file->refresh()->directory_id);
        $this->assertSame($url, Storage::disk('public')->url($file->path));
        $audits = Activity::query()->where('event', 'file_moved')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertNull($audits[0]->properties['previous_directory_id']);
        $this->assertSame($directory->id, $audits[0]->properties['directory_id']);
        $this->assertSame($directory->id, $audits[1]->properties['previous_directory_id']);
        $this->assertNull($audits[1]->properties['directory_id']);
        $this->putJson(ApiRouting::path('/admin/files/'.$file->id), [], $headers)->assertUnprocessable();
        $this->putJson(ApiRouting::path('/admin/files/'.$file->id), ['directory_id' => 999999], $headers)->assertUnprocessable();
        $this->putJson(ApiRouting::path('/admin/files/by-url'), ['url' => $url.'?download=1', 'directory_id' => null], $headers)->assertNotFound();
    }

    public function test_pending_failed_and_deleting_files_cannot_move(): void
    {
        $headers = $this->headersFor(['system.file.update']);
        foreach ([['status' => 'pending'], ['status' => 'failed'], ['deletion_token' => fake()->uuid()]] as $attributes) {
            $file = File::factory()->create($attributes);
            $this->putJson(ApiRouting::path('/admin/files/'.$file->id), ['directory_id' => null], $headers)
                ->assertUnprocessable()->assertJsonValidationErrors('file');
        }
    }

    public function test_deleting_by_url_only_resolves_existing_files_on_the_public_disk(): void
    {
        Storage::fake('public');
        $headers = $this->headersFor(['system.file.delete']);
        $file = File::factory()->create();
        Storage::disk('public')->put($file->path, 'test');
        $url = Storage::disk('public')->url($file->path);
        $endpoint = ApiRouting::path('/admin/files/by-url');
        foreach (['https://external.test/file.pdf', $url.'?download=1', Storage::disk('public')->url('missing.pdf')] as $invalidUrl) {
            $this->deleteJson($endpoint.'?'.http_build_query(['url' => $invalidUrl]), [], $headers)->assertNotFound();
        }
        $this->deleteJson($endpoint.'?'.http_build_query(['url' => $url]), [], $headers)->assertOk();
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        Storage::disk('public')->assertMissing($file->path);
    }

    #[DataProvider('protectedEndpointProvider')]
    public function test_file_directory_and_url_operations_require_their_own_permissions(string $method, string $path, array $body): void
    {
        FileDirectory::factory()->create(['id' => 1]);
        File::factory()->create(['id' => 1]);
        $headers = $this->headersFor(['system.file.view']);
        $this->json($method, ApiRouting::path($path), $body)->assertUnauthorized();
        $this->json($method, ApiRouting::path($path), $body, $headers)->assertForbidden();
    }

    public static function protectedEndpointProvider(): array
    {
        return [
            ['POST', '/admin/file-directories', ['name' => 'Blocked']],
            ['DELETE', '/admin/file-directories/1', []],
            ['PUT', '/admin/files/1', ['directory_id' => null]],
            ['PUT', '/admin/files/by-url', ['url' => '/storage/test', 'directory_id' => null]],
            ['DELETE', '/admin/files/by-url', ['url' => '/storage/test']],
        ];
    }

    private function headersFor(array $permissions): array
    {
        return ['Authorization' => 'Bearer '.$this->managerTokenFor($permissions)];
    }
}

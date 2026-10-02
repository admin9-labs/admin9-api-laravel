<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FrameworkSurfaceAccessTest extends TestCase
{
    public function test_documentation_is_not_public_outside_the_local_environment(): void
    {
        $this->app->instance('env', 'production');

        $this->getJson('/docs/api')->assertForbidden();
        $this->getJson('/docs/api.json')->assertForbidden();
    }

    public function test_private_files_require_a_valid_unexpired_download_signature(): void
    {
        Storage::fake('local')->put('audit-private.txt', 'private test bytes');
        $url = URL::temporarySignedRoute('storage.local', now()->addMinute(), [
            'path' => 'audit-private.txt',
        ], absolute: false);

        $this->get('/storage/audit-private.txt')->assertForbidden();
        $this->get($url)->assertOk()->assertHeader('Content-Security-Policy');
        $this->travel(2)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_unsigned_uploads_and_download_signatures_cannot_write_private_files(): void
    {
        $disk = Storage::fake('local');
        $url = URL::temporarySignedRoute('storage.local', now()->addMinute(), [
            'path' => 'audit-upload.txt',
        ], absolute: false);

        $this->put('/storage/audit-upload.txt', ['content' => 'not allowed'])->assertForbidden();
        $this->put($url, ['content' => 'not allowed'])->assertForbidden();
        $disk->assertMissing('audit-upload.txt');
    }

    public function test_upload_signatures_cannot_read_existing_private_files(): void
    {
        Storage::fake('local')->put('audit-private.txt', 'private test bytes');
        $url = URL::temporarySignedRoute('storage.local.upload', now()->addMinute(), [
            'path' => 'audit-private.txt',
            'upload' => true,
        ], absolute: false);

        $this->get($url)->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Mitoop\Http\Exceptions\ClientSafeException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HttpErrorStatusTest extends TestCase
{
    #[DataProvider('exactApiRootProvider')]
    public function test_exact_api_roots_return_not_found_with_request_ids(string $uri): void
    {
        $response = $this->get($uri)
            ->assertNotFound()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 404);

        $this->assertResponseRequestIdMatchesHeader($response);
    }

    #[DataProvider('webAcceptProvider')]
    public function test_missing_web_paths_keep_json_bodies_with_real_not_found_status(string $accept): void
    {
        $this->withHeader('Accept', $accept)
            ->get('/_test/generic-missing')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('X-Request-Id')
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 404)
            ->assertJsonMissingPath('request_id');
    }

    public function test_missing_storage_paths_keep_the_environment_specific_error_with_a_real_status(): void
    {
        $expectedStatus = app()->isProduction() ? 404 : 403;

        $this->get('/storage/media/_test/missing-image.png')
            ->assertStatus($expectedStatus)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('X-Request-Id')
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', $expectedStatus)
            ->assertJsonMissingPath('request_id');
    }

    #[DataProvider('apiHttpStatusProvider')]
    public function test_api_http_exceptions_keep_their_status_and_request_ids(int $status): void
    {
        $uri = '/api/_test/http-error-'.$status;
        Route::middleware('api')->get($uri, function () use ($status): void {
            throw new HttpException($status, 'Expected HTTP error');
        });

        $response = $this->getJson($uri)
            ->assertStatus($status)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', $status)
            ->assertHeader('X-Request-Id');

        $this->assertResponseRequestIdMatchesHeader($response);
    }

    public function test_method_not_allowed_errors_keep_their_status_allow_header_and_request_id(): void
    {
        Route::middleware('api')->get('/api/_test/get-only', static fn (): array => ['ok' => true]);

        $response = $this->postJson('/api/_test/get-only')
            ->assertStatus(405)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 405)
            ->assertHeader('X-Request-Id');

        $this->assertStringContainsString('GET', implode(', ', $response->headers->all('Allow')));
        $this->assertResponseRequestIdMatchesHeader($response);
    }

    public function test_valid_http_payload_codes_are_used_only_as_a_fallback(): void
    {
        Route::middleware('api')->get('/api/_test/client-safe-conflict', function (): void {
            throw new ClientSafeException('Conflict', errorCode: 409);
        });

        $response = $this->getJson('/api/_test/client-safe-conflict')
            ->assertConflict()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 409)
            ->assertHeader('X-Request-Id');

        $this->assertResponseRequestIdMatchesHeader($response);
    }

    public function test_business_error_codes_do_not_become_http_statuses(): void
    {
        Route::middleware('api')->get('/api/_test/client-safe-business-error', function (): void {
            throw new ClientSafeException('Business error', errorCode: 1001);
        });

        $response = $this->getJson('/api/_test/client-safe-business-error')
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 1001)
            ->assertHeader('X-Request-Id');

        $this->assertResponseRequestIdMatchesHeader($response);
    }

    public function test_successful_web_responses_are_not_changed(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertHeaderMissing('X-Request-Id');
    }

    public static function exactApiRootProvider(): array
    {
        return [
            'api without trailing slash' => ['/api'],
            'api with trailing slash' => ['/api/'],
        ];
    }

    public static function webAcceptProvider(): array
    {
        return [
            'browser accept' => ['text/html'],
            'json accept' => ['application/json'],
        ];
    }

    public static function apiHttpStatusProvider(): array
    {
        return [
            'forbidden' => [403],
            'not found' => [404],
            'unprocessable' => [422],
            'service unavailable' => [503],
        ];
    }

    private function assertResponseRequestIdMatchesHeader(TestResponse $response): void
    {
        $requestId = $response->json('request_id');

        $this->assertIsString($requestId);
        $this->assertSame($requestId, $response->headers->get('X-Request-Id'));
    }
}

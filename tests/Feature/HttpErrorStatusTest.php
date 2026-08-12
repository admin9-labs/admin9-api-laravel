<?php

namespace Tests\Feature;

use App\Support\ApiRouting;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Mitoop\Http\Exceptions\ClientSafeException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HttpErrorStatusTest extends TestCase
{
    public function test_admin_and_member_api_routes_are_registered_under_the_application_api_prefix(): void
    {
        $this->postJson(ApiRouting::path('/admin/auth/login'))->assertUnprocessable()->assertHeader('X-Request-Id');
        $this->postJson(ApiRouting::path('/auth/login'))->assertUnprocessable()->assertHeader('X-Request-Id');
    }

    #[DataProvider('legacyRootApiPathProvider')]
    public function test_legacy_root_api_paths_return_not_found_outside_the_api_context(string $uri): void
    {
        $this->get($uri)->assertNotFound()->assertHeaderMissing('X-Request-Id')->assertJsonPath('success', false)->assertJsonPath('code', 404)->assertJsonMissingPath('request_id');
    }

    #[DataProvider('webAcceptProvider')]
    public function test_missing_backend_paths_keep_json_bodies_with_real_not_found_status(string $accept): void
    {
        $this->withHeader('Accept', $accept)->get('/_test/generic-missing')->assertNotFound()->assertHeader('Content-Type', 'application/json')->assertHeaderMissing('X-Request-Id')->assertJsonPath('success', false)->assertJsonPath('code', 404)->assertJsonMissingPath('request_id');
    }

    #[DataProvider('apiHttpStatusProvider')]
    public function test_api_http_exceptions_keep_their_status_and_request_ids(int $status): void
    {
        $uri = ApiRouting::path('/_test/http-error-').$status;
        Route::middleware('api')->get($uri, function () use ($status): void {
            throw new HttpException($status, 'Expected HTTP error');
        });
        $response = $this->getJson($uri)->assertStatus($status)->assertJsonPath('success', false)->assertJsonPath('code', $status)->assertHeader('X-Request-Id');
        $this->assertResponseRequestIdMatchesHeader($response);
    }

    public function test_method_not_allowed_errors_keep_their_status_allow_header_and_request_id(): void
    {
        Route::middleware('api')->get(ApiRouting::path('/_test/get-only'), static fn (): array => ['ok' => true]);
        $response = $this->postJson(ApiRouting::path('/_test/get-only'))->assertStatus(405)->assertJsonPath('success', false)->assertJsonPath('code', 405)->assertHeader('X-Request-Id');
        $this->assertStringContainsString('GET', implode(', ', $response->headers->all('Allow')));
        $this->assertResponseRequestIdMatchesHeader($response);
    }

    public function test_valid_http_payload_codes_are_used_only_as_a_fallback(): void
    {
        Route::middleware('api')->get(ApiRouting::path('/_test/client-safe-conflict'), function (): void {
            throw new ClientSafeException('Conflict', errorCode: 409);
        });
        $response = $this->getJson(ApiRouting::path('/_test/client-safe-conflict'))->assertConflict()->assertJsonPath('success', false)->assertJsonPath('code', 409)->assertHeader('X-Request-Id');
        $this->assertResponseRequestIdMatchesHeader($response);
    }

    public function test_business_error_codes_do_not_become_http_statuses(): void
    {
        Route::middleware('api')->get(ApiRouting::path('/_test/client-safe-business-error'), function (): void {
            throw new ClientSafeException('Business error', errorCode: 1001);
        });
        $response = $this->getJson(ApiRouting::path('/_test/client-safe-business-error'))->assertOk()->assertJsonPath('success', false)->assertJsonPath('code', 1001)->assertHeader('X-Request-Id');
        $this->assertResponseRequestIdMatchesHeader($response);
    }

    public function test_successful_web_responses_keep_their_payload_outside_the_api_context(): void
    {
        $this->get('/')->assertOk()->assertJsonPath('status', 'ok')->assertHeaderMissing('X-Request-Id');
    }

    public static function legacyRootApiPathProvider(): array
    {
        return ['admin' => ['/admin/auth/me'], 'member' => ['/auth/me']];
    }

    public static function webAcceptProvider(): array
    {
        return ['browser' => ['text/html'], 'json' => ['application/json']];
    }

    public static function apiHttpStatusProvider(): array
    {
        return ['forbidden' => [403], 'not found' => [404], 'unprocessable' => [422], 'service unavailable' => [503]];
    }

    private function assertResponseRequestIdMatchesHeader(TestResponse $response): void
    {
        $requestId = $response->json('request_id');
        $this->assertIsString($requestId);
        $this->assertSame($requestId, $response->headers->get('X-Request-Id'));
    }
}

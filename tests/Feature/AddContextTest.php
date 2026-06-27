<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mitoop\Http\JsonResponder;
use Mitoop\Http\JsonResponderDefault;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 验证 AddContext middleware 的 API 请求追踪契约。
 *
 * 测试内注册临时路由，避免依赖业务/示例路由；
 * 这样路由文件调整时，不会误伤 middleware 行为测试。
 */
class AddContextTest extends TestCase
{
    public function test_it_exposes_request_id_to_success_responses(): void
    {
        Route::middleware('api')->get('/api/_test/add-context', function (JsonResponder $responder) {
            return $responder->success();
        });

        $response = $this->getJson('/api/_test/add-context')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertHeader('X-Request-Id');

        $this->assertResponseRequestIdMatchesHeader($response);
    }

    public function test_it_exposes_request_id_to_error_responses(): void
    {
        Route::middleware('api')->get('/api/_test/add-context-error', function (JsonResponder $responder) {
            return $responder->error('failed');
        });

        $response = $this->getJson('/api/_test/add-context-error')
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'failed')
            ->assertHeader('X-Request-Id');

        $this->assertResponseRequestIdMatchesHeader($response);
    }

    public function test_it_exposes_request_id_to_deny_responses(): void
    {
        Route::middleware('api')->get('/api/_test/add-context-deny', function (JsonResponder $responder) {
            return $responder->deny();
        });

        $response = $this->getJson('/api/_test/add-context-deny')
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', -1)
            ->assertHeader('X-Request-Id');

        $this->assertResponseRequestIdMatchesHeader($response);
    }

    public function test_it_exposes_request_id_to_api_route_not_found_payloads(): void
    {
        // 当前 Mitoop 响应契约把错误码放在 JSON code，HTTP 状态保持包默认行为。
        $response = $this->getJson('/api/_test/missing-route')
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 404)
            ->assertHeader('X-Request-Id');

        $this->assertResponseRequestIdMatchesHeader($response);
    }

    public function test_it_exposes_request_id_when_default_middleware_rejects_api_requests(): void
    {
        $response = $this->withServerVariables([
            'CONTENT_LENGTH' => PHP_INT_MAX,
        ])->postJson('/api/_test/add-context-rejected')
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 413)
            ->assertHeader('X-Request-Id');

        $this->assertResponseRequestIdMatchesHeader($response);
    }

    public function test_it_generates_a_fresh_request_id_for_each_request(): void
    {
        Route::middleware('api')->get('/api/_test/add-context', function (JsonResponder $responder) {
            return $responder->success();
        });

        $first = $this->getJson('/api/_test/add-context')
            ->assertOk()
            ->json('request_id');

        $second = $this->getJson('/api/_test/add-context')
            ->assertOk()
            ->json('request_id');

        $this->assertIsString($first);
        $this->assertIsString($second);
        $this->assertTrue(Str::isUuid($first));
        $this->assertTrue(Str::isUuid($second));
        $this->assertNotSame($first, $second);
    }

    public function test_it_adds_safe_request_data_to_laravel_context(): void
    {
        Route::middleware('api')->get('/api/_test/add-context', function () {
            return response()->json([
                'context_request_id' => Context::get('request_id'),
                'context_method' => Context::get('method'),
                'context_path' => Context::get('path'),
                'context_url' => Context::get('url'),
            ]);
        });

        $response = $this->getJson('/api/_test/add-context?token=secret')
            ->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('context_method', 'GET')
            ->assertJsonPath('context_path', 'api/_test/add-context')
            ->assertJsonPath('context_url', null);

        $requestId = $response->json('context_request_id');

        $this->assertIsString($requestId);
        $this->assertTrue(Str::isUuid($requestId));
        $this->assertSame($requestId, $response->headers->get('X-Request-Id'));
    }

    public function test_it_does_not_store_request_id_in_the_responder_singleton(): void
    {
        Route::middleware('api')->get('/api/_test/add-context', function (JsonResponder $responder) {
            return $responder->success();
        });

        $this->getJson('/api/_test/add-context')
            ->assertOk();

        $rawExtra = (new ReflectionProperty(JsonResponderDefault::class, 'extra'))
            ->getValue(app(JsonResponderDefault::class));

        $this->assertArrayNotHasKey('request_id', $rawExtra);
    }

    public function test_it_does_not_leak_request_id_to_later_web_responder_payloads(): void
    {
        Route::middleware('api')->get('/api/_test/add-context', function (JsonResponder $responder) {
            return $responder->success();
        });

        Route::get('/_test/add-context-web-responder', function (JsonResponder $responder) {
            return $responder->success();
        });

        $this->getJson('/api/_test/add-context')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('request_id', fn ($requestId) => is_string($requestId));

        $this->getJson('/_test/add-context-web-responder')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('request_id')
            ->assertHeaderMissing('X-Request-Id');
    }

    public function test_it_does_not_force_request_id_onto_web_responses(): void
    {
        Route::get('/_test/add-context-web', function () {
            return response()->json(['ok' => true]);
        });

        $this->getJson('/_test/add-context-web')
            ->assertOk()
            ->assertJsonMissingPath('request_id')
            ->assertHeaderMissing('X-Request-Id');
    }

    private function assertResponseRequestIdMatchesHeader(TestResponse $response): void
    {
        $requestId = $response->json('request_id');

        $this->assertIsString($requestId);
        $this->assertTrue(Str::isUuid($requestId));
        $this->assertSame($requestId, $response->headers->get('X-Request-Id'));
    }
}

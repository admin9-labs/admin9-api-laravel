<?php

namespace Tests\Feature;

use App\Exceptions\MediaDeleteFailedException;
use App\Support\Auth\AccountInactiveException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ApiErrorContractTest extends TestCase
{
    public function test_authentication_errors_use_the_stable_object_envelope(): void
    {
        $response = $this->getJson('/api/admin/auth/me');

        $payload = $this->assertErrorEnvelope($response, 401);

        $this->assertSame('Unauthenticated', $payload->message);
        $this->assertObjectNotHasProperty('error_code', $payload);
    }

    public function test_account_inactive_errors_have_a_machine_code_distinct_from_permission_denials(): void
    {
        Route::middleware('api')->get('/api/_test/account-inactive', function (): void {
            throw new AccountInactiveException;
        });
        Route::middleware('api')->get('/api/_test/permission-denied', function (): void {
            throw new HttpException(403, 'Forbidden');
        });

        $inactivePayload = $this->assertErrorEnvelope(
            $this->getJson('/api/_test/account-inactive'),
            403,
        );
        $permissionPayload = $this->assertErrorEnvelope(
            $this->getJson('/api/_test/permission-denied'),
            403,
        );

        $this->assertSame(AccountInactiveException::ERROR_CODE, $inactivePayload->error_code);
        $this->assertObjectNotHasProperty('error_code', $permissionPayload);
    }

    public function test_not_found_errors_use_the_stable_object_envelope(): void
    {
        $this->assertErrorEnvelope($this->getJson('/api/_test/missing'), 404);
    }

    public function test_validation_errors_keep_a_field_to_string_array_object(): void
    {
        $payload = $this->assertErrorEnvelope(
            $this->postJson('/api/admin/auth/login'),
            422,
            expectEmptyErrors: false,
        );
        $errors = get_object_vars($payload->errors);

        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('password', $errors);

        foreach ($errors as $messages) {
            $this->assertIsArray($messages);
            $this->assertNotEmpty($messages);
            $this->assertContainsOnly('string', $messages);
        }
    }

    public function test_application_controlled_content_too_large_errors_use_the_stable_object_envelope(): void
    {
        $response = $this->withServerVariables([
            'CONTENT_LENGTH' => PHP_INT_MAX,
        ])->postJson('/api/_test/content-too-large');

        $this->assertErrorEnvelope($response, 413);
    }

    public function test_rate_limit_errors_keep_object_envelopes_and_rate_headers(): void
    {
        Route::middleware('api')->get('/api/_test/rate-limited', function (): void {
            throw new ThrottleRequestsException('Too Many Attempts.', headers: [
                'Retry-After' => '60',
                'X-RateLimit-Limit' => '5',
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) (time() + 60),
            ]);
        });

        $response = $this->getJson('/api/_test/rate-limited')
            ->assertHeader('Retry-After', '60')
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset');

        $this->assertErrorEnvelope($response, 429);
    }

    public function test_unhandled_exceptions_return_http_500_without_debug_details(): void
    {
        config()->set('app.debug', true);
        Route::middleware('api')->get('/api/_test/server-error', function (): void {
            throw new RuntimeException('sensitive internal detail');
        });

        $payload = $this->assertErrorEnvelope($this->getJson('/api/_test/server-error'), 500);

        $this->assertSame('Something went wrong', $payload->message);
        $this->assertStringNotContainsString('sensitive internal detail', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_media_delete_failures_use_the_stable_service_unavailable_envelope(): void
    {
        Route::middleware('api')->delete('/api/_test/media-delete-failed', function (): void {
            throw new MediaDeleteFailedException;
        });

        $payload = $this->assertErrorEnvelope(
            $this->deleteJson('/api/_test/media-delete-failed'),
            503,
        );

        $this->assertSame(MediaDeleteFailedException::ERROR_CODE, $payload->error_code);
    }

    private function assertErrorEnvelope(
        TestResponse $response,
        int $status,
        bool $expectEmptyErrors = true,
    ): stdClass {
        $response
            ->assertStatus($status)
            ->assertHeader('X-Request-Id');

        $payload = json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR);

        $this->assertInstanceOf(stdClass::class, $payload);
        $this->assertFalse($payload->success);
        $this->assertSame($status, $payload->code);
        $this->assertIsString($payload->message);
        $this->assertInstanceOf(stdClass::class, $payload->data);
        $this->assertSame([], get_object_vars($payload->data));
        $this->assertInstanceOf(stdClass::class, $payload->errors);
        $this->assertIsString($payload->request_id);
        $this->assertSame($payload->request_id, $response->headers->get('X-Request-Id'));

        if ($expectEmptyErrors) {
            $this->assertSame([], get_object_vars($payload->errors));
        }

        return $payload;
    }
}

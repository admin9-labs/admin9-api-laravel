<?php

namespace Tests\Unit;

use App\Support\Security\SensitiveDataSanitizer;
use PHPUnit\Framework\TestCase;

class SensitiveDataSanitizerTest extends TestCase
{
    public function test_sensitive_key_variants_are_detected(): void
    {
        foreach ([
            'password',
            'remember_token',
            'api_token',
            'client_secret',
            'jwt',
            'authorization',
            'api_key',
            'api-key',
            'api.key',
            'apikey',
        ] as $key) {
            $this->assertTrue(
                SensitiveDataSanitizer::isSensitiveKey($key),
                sprintf('[%s] should be treated as a sensitive key.', $key),
            );
        }
    }

    public function test_sensitive_keys_are_removed_recursively_without_masking_safe_values(): void
    {
        $payload = [
            'name' => 'visible',
            'password' => 'secret-password',
            'nested' => [
                'api_key' => 'secret-api-key',
                'safe' => 'value',
                'deeper' => [
                    'authorization_header' => 'Bearer token',
                    'visible' => 'still here',
                ],
            ],
            'items' => [
                ['token' => 'secret-token', 'label' => 'first'],
                ['api.key' => 'secret-api-key', 'label' => 'second'],
            ],
        ];

        $this->assertSame([
            'name' => 'visible',
            'nested' => [
                'safe' => 'value',
                'deeper' => [
                    'visible' => 'still here',
                ],
            ],
            'items' => [
                ['label' => 'first'],
                ['label' => 'second'],
            ],
        ], SensitiveDataSanitizer::removeSensitiveKeys($payload));
    }
}

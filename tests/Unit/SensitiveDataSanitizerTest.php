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

    public function test_url_credentials_are_removed_without_changing_safe_parameters_or_other_strings(): void
    {
        $this->assertSame([
            'url' => 'https://example.test/file?id=1&id=2#state=visible',
            'nested' => ['https://cdn.example.test/cover.jpg?width=1200#preview'],
            'relative' => '/storage/file?token=routing-value',
            'plain' => 'visible',
            'safe_url' => 'https://example.test/file?note=a;b&id=1&id=2#signature-section',
        ], SensitiveDataSanitizer::removeSensitiveKeys([
            'url' => 'https://user:password@example.test/file?id=1&%74oken=credential&id=2#access_token=credential&state=visible',
            'nested' => ['https://cdn.example.test/cover.jpg?width=1200&X-Amz-Signature=credential#preview'],
            'relative' => '/storage/file?token=routing-value',
            'plain' => 'visible',
            'safe_url' => 'https://example.test/file?note=a;b&id=1&id=2#signature-section',
        ]));
    }

    public function test_signed_url_parameter_variants_are_removed(): void
    {
        $this->assertSame([
            'url' => 'https://example.test/file?id=1',
        ], SensitiveDataSanitizer::removeSensitiveKeys([
            'url' => 'https://example.test/file?id=1&sig=a&api_key=b&AWSAccessKeyId=c&Key-Pair-Id=d&Policy=e',
        ]));
    }
}

<?php

namespace Tests\Unit;

use App\Models\SystemConfig;
use PHPUnit\Framework\TestCase;

class SensitiveConfigurationTest extends TestCase
{
    public function test_sensitive_configuration_terms_are_detected(): void
    {
        foreach ([
            'mail.password',
            'access_token',
            'client-secret',
            'jwt.signing.key',
            'authorization_header',
            'payment.api_key',
            'payment.api-key',
            'payment.api.key',
            'payment.apikey',
        ] as $value) {
            $this->assertTrue(
                SystemConfig::containsSensitiveConfiguration($value),
                sprintf('[%s] should be treated as sensitive.', $value),
            );
        }
    }

    public function test_public_configuration_terms_are_allowed(): void
    {
        foreach ([
            'site.name',
            'homepage_banner',
            'public_contact_email',
            'feature.flags',
            null,
            ['password' => 'not scanned because arrays are not scalar config fields'],
        ] as $value) {
            $this->assertFalse(
                SystemConfig::containsSensitiveConfiguration($value),
                sprintf('[%s] should not be treated as a scalar sensitive configuration.', json_encode($value)),
            );
        }
    }
}

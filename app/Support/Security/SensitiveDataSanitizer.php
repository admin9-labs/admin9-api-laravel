<?php

namespace App\Support\Security;

class SensitiveDataSanitizer
{
    private const SENSITIVE_PATTERN = '/password|token|secret|jwt|authorization|api[\s._-]*key/i';

    public static function containsSensitiveTerm(mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        return preg_match(self::SENSITIVE_PATTERN, (string) $value) === 1;
    }

    public static function isSensitiveKey(string $key): bool
    {
        return self::containsSensitiveTerm($key);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function removeSensitiveKeys(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                continue;
            }

            $sanitized[$key] = is_array($value)
                ? self::removeSensitiveKeys($value)
                : $value;
        }

        return $sanitized;
    }
}

<?php

namespace App\Support\Security;

use Illuminate\Support\Uri;
use Throwable;

class SensitiveDataSanitizer
{
    private const SENSITIVE_PATTERN = '/password|token|secret|jwt|authorization|api[\s._-]*key/i';

    private const URL_CREDENTIAL_PATTERN = '/password|token|secret|jwt|authorization|signature|credential|policy|(?:api|access)[\s._-]*key(?:[\s._-]*id)?|key[\s._-]*pair[\s._-]*id|(?:^|[\s._-])sig(?:$|[\s._-])/i';

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

            $sanitized[$key] = match (true) {
                is_array($value) => self::removeSensitiveKeys($value),
                is_string($value) => self::sanitizeUrl($value),
                default => $value,
            };
        }

        return $sanitized;
    }

    private static function sanitizeUrl(string $value): string
    {
        try {
            $uri = Uri::of($value);
        } catch (Throwable) {
            return $value;
        }

        if (! in_array(strtolower($uri->scheme() ?? ''), ['http', 'https'], true)
            || $uri->host() === null || $uri->host() === '') {
            return $value;
        }

        $query = $uri->query()->value();
        $sanitizedQuery = self::sanitizeQuery($query);
        $fragment = $uri->fragment();
        $sanitizedFragment = is_string($fragment) && str_contains($fragment, '=')
            ? self::sanitizeQuery($fragment)
            : $fragment;

        if ($uri->user(withPassword: true) === null
            && $sanitizedQuery === $query && $sanitizedFragment === $fragment) {
            return $value;
        }

        $sanitizedUri = $uri->withUser(null);

        if ($sanitizedQuery !== $query) {
            $sanitizedUri = Uri::of($sanitizedUri->getUri()->withQuery($sanitizedQuery === '' ? null : $sanitizedQuery));
        }

        if ($sanitizedFragment !== $fragment) {
            $sanitizedUri = $sanitizedFragment === ''
                ? $sanitizedUri->withoutFragment()
                : $sanitizedUri->withFragment($sanitizedFragment);
        }

        return $sanitizedUri->value();
    }

    private static function sanitizeQuery(string $query): string
    {
        $pairs = explode('&', $query);
        $safePairs = array_values(array_filter($pairs, static function (string $pair): bool {
            [$key] = explode('=', $pair, 2);

            return preg_match(self::URL_CREDENTIAL_PATTERN, urldecode($key)) !== 1;
        }));

        return count($safePairs) === count($pairs) ? $query : implode('&', $safePairs);
    }
}

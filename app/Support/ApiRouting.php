<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ApiRouting
{
    public const PREFIX = '';

    public static function path(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        if (self::PREFIX === '') {
            return $path;
        }

        return '/'.trim(self::PREFIX, '/').($path === '/' ? '' : $path);
    }

    public static function matches(Request $request): bool
    {
        if (self::PREFIX === '') {
            return true;
        }

        $prefix = trim(self::PREFIX, '/');

        return $request->is($prefix, $prefix.'/*');
    }
}

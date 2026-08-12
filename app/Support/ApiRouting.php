<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ApiRouting
{
    public static function prefix(): string
    {
        return trim((string) config('app.api_route_prefix'), '/');
    }

    public static function path(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $prefix = self::prefix();

        if ($prefix === '') {
            return $path;
        }

        return '/'.$prefix.($path === '/' ? '' : $path);
    }

    public static function matches(Request $request): bool
    {
        $prefix = self::prefix();

        if ($prefix === '') {
            return true;
        }

        return $request->is($prefix, $prefix.'/*');
    }
}

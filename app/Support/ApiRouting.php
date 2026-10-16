<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use InvalidArgumentException;
use LogicException;

final class ApiRouting
{
    public static function prefix(): string
    {
        return self::normalizePrefix('app.api_route_prefix');
    }

    public static function adminPrefix(): string
    {
        return self::normalizePrefix('app.admin_api_route_prefix');
    }

    public static function domain(): ?string
    {
        return self::normalizeDomain('app.api_domain');
    }

    public static function adminDomain(): ?string
    {
        return self::normalizeDomain('app.admin_api_domain');
    }

    public static function path(string $path): string
    {
        return self::prefixedPath(self::prefix(), $path);
    }

    public static function adminPath(string $path): string
    {
        return self::prefixedPath(self::adminPrefix(), $path);
    }

    public static function matches(Request $request): bool
    {
        foreach ([[self::domain(), self::prefix()], [self::adminDomain(), self::adminPrefix()]] as [$domain, $prefix]) {
            if (($domain === null || $request->getHost() === $domain)
                && ($prefix === '' || $request->is($prefix, $prefix.'/*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int|string, string|list<string>>
     */
    public static function corsPaths(): array
    {
        $globalPaths = ['sanctum/csrf-cookie'];
        $hostPaths = [];

        foreach ([[self::domain(), self::prefix()], [self::adminDomain(), self::adminPrefix()]] as [$domain, $prefix]) {
            $paths = $prefix === '' ? ['*'] : [$prefix, $prefix.'/*'];

            if ($domain === null) {
                $globalPaths = [...$globalPaths, ...$paths];
            } else {
                $hostPaths[$domain] = [...($hostPaths[$domain] ?? []), ...$paths];
            }
        }

        $paths = array_values(array_unique($globalPaths));

        foreach ($hostPaths as $domain => $scopedPaths) {
            $paths[$domain] = array_values(array_unique([...$globalPaths, ...$scopedPaths]));
        }

        return $paths;
    }

    /**
     * @param  array<array-key, Route>  $clientRoutes
     * @param  array<array-key, Route>  $adminRoutes
     */
    public static function assertNoRouteConflicts(array $clientRoutes, array $adminRoutes): void
    {
        if (self::domain() !== self::adminDomain()) {
            return;
        }

        foreach ($clientRoutes as $clientRoute) {
            if (str_contains($clientRoute->uri(), '{')) {
                continue;
            }

            foreach ($clientRoute->methods() as $method) {
                $request = Request::create('http://'.(self::domain() ?? 'localhost').'/'.$clientRoute->uri(), $method);

                foreach ($adminRoutes as $adminRoute) {
                    if ($adminRoute->matches($request)) {
                        throw new LogicException(sprintf(
                            'API route conflict: %s %s [%s] overlaps %s [%s]. Configure distinct domains or non-conflicting prefixes.',
                            $method,
                            $clientRoute->uri(),
                            $clientRoute->getName(),
                            $adminRoute->uri(),
                            $adminRoute->getName(),
                        ));
                    }
                }
            }
        }
    }

    private static function prefixedPath(string $prefix, string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return $prefix === '' ? $path : '/'.$prefix.($path === '/' ? '' : $path);
    }

    private static function normalizePrefix(string $key): string
    {
        $prefix = trim((string) config($key), '/');

        if ($prefix !== '' && (! preg_match('#^[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*$#D', $prefix)
            || in_array('.', explode('/', $prefix), true)
            || in_array('..', explode('/', $prefix), true))) {
            throw new InvalidArgumentException("{$key} must be an empty prefix or a static URL path.");
        }

        return $prefix;
    }

    private static function normalizeDomain(string $key): ?string
    {
        $domain = strtolower(trim((string) config($key)));

        if ($domain === '') {
            return null;
        }

        if (! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new InvalidArgumentException("{$key} must be a hostname without a scheme, port, path, or placeholders.");
        }

        return rtrim($domain, '.');
    }
}

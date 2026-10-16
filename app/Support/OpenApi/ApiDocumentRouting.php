<?php

namespace App\Support\OpenApi;

use App\Support\ApiRouting;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use LogicException;

final class ApiDocumentRouting
{
    public function origin(bool $admin): string
    {
        $url = parse_url((string) config('app.url'));
        $scheme = strtolower($url['scheme'] ?? '');
        $host = ($admin ? ApiRouting::adminDomain() : ApiRouting::domain()) ?? ($url['host'] ?? null);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            throw new LogicException('API documentation requires an absolute HTTP(S) APP_URL.');
        }

        return $scheme.'://'.strtolower(rtrim($host, '.')).(isset($url['port']) ? ':'.$url['port'] : '');
    }

    public function assertCanonicalDestination(Route $route): void
    {
        if ($route->getDomain() !== null) {
            return;
        }

        $admin = str_starts_with($route->getName() ?? '', 'admin.');
        $origin = $this->origin($admin);
        $host = Request::create($origin)->getHost();

        foreach (RouteFacade::getRoutes() as $candidate) {
            if ($candidate->getDomain() !== $host) {
                continue;
            }

            $path = str_contains($route->uri(), '{') ? $candidate->uri() : $route->uri();

            if (str_contains($path, '{')) {
                continue;
            }

            foreach (array_intersect($candidate->methods(), $route->methods()) as $method) {
                $request = Request::create($origin.'/'.$path, $method);

                if ($route->matches($request) && $candidate->matches($request)) {
                    throw new LogicException(sprintf(
                        'APP_URL origin [%s] sends [%s] to [%s]. Choose an APP_URL host outside the other API domain or bind this API to its own domain.',
                        $origin,
                        $route->getName(),
                        $candidate->getName(),
                    ));
                }
            }
        }
    }

    public function describeSharedSettings(OpenApi $document): void
    {
        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                if ($operation->operationId === 'system-settings.public') {
                    $path->servers([]);
                    $operation->servers([new Server($this->origin(false))]);
                }
            }
        }
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\ApiRouting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AddContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ApiRouting::matches($request)) {
            return $next($request);
        }

        $requestId = (string) Str::uuid7();

        Context::add([
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
        ]);

        try {
            $response = $next($request);
            $response->headers->set('X-Request-Id', $requestId);

            return $response;
        } finally {
            Context::forget(['request_id', 'method', 'path']);
        }
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Request-Id');
        $requestId = is_string($incoming) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $incoming)
            ? $incoming : (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        $started = microtime(true);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        logger()->info('http.request', [
            'request_id' => $requestId,
            'user_id' => optional($request->user())->getAuthIdentifier(),
            'route' => $request->route()?->uri(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
        ]);

        return $response;
    }
}

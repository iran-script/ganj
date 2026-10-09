<?php

namespace App\Http\Middleware;

use App\Services\Identity\DeviceResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        app(DeviceResolver::class)->fromRequest($request);

        return $next($request);
    }
}

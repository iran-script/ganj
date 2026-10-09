<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Identity\DeviceResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::forgetGuards();
        $user = $request->bearerToken() ? Auth::guard('sanctum')->user() : null;
        if (! $user instanceof User) {
            throw new ApiException('AUTHENTICATION_REQUIRED', 'Authentication is required.', [], 401);
        }
        $user->ensureActive();
        $device = $user->devices()->whereKey($user->currentAccessToken()->user_device_id)->whereNull('revoked_at')->first();
        if (! $device) {
            throw new ApiException('AUTHENTICATION_REQUIRED', 'The token device is no longer active.', [], 401);
        }
        $request->setUserResolver(fn () => $user);
        if ($request->hasHeader('X-Device-Id')) {
            app(DeviceResolver::class)->fromRequest($request);
        }

        return $next($request);
    }
}

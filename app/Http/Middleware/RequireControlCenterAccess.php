<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class RequireControlCenterAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        if ($actor === null) {
            throw new ApiException('AUTHENTICATION_REQUIRED', 'Authentication is required.', [], 401);
        }

        $actor->ensureActive();
        $token = $actor->currentAccessToken();
        $webDeviceActive = $token && $actor->devices()->whereKey($token->user_device_id)
            ->where('platform', 'web')->whereNull('revoked_at')->exists();
        if (! $token || ! $webDeviceActive || ! in_array('control-center', $token->abilities, true)) {
            throw new ApiException('AUTHORIZATION_DENIED', 'A Control Center session is required.', [], 403);
        }
        Gate::forUser($actor)->authorize('admin.players.view');

        return $next($request);
    }
}

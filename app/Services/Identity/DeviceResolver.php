<?php

namespace App\Services\Identity;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceResolver
{
    public function resolve(User $user, ?string $uuid): UserDevice
    {
        $user->ensureActive();
        if (! is_string($uuid) || ! Str::isUuid($uuid)) {
            throw new ApiException('AUTHORIZATION_DENIED', 'A registered active device is required.', [], 403);
        }
        $device = $user->devices()->where('device_uuid', strtolower($uuid))->whereNull('revoked_at')->first();
        if (! $device) {
            throw new ApiException('AUTHORIZATION_DENIED', 'A registered active device is required.', [], 403);
        }

        return $device;
    }

    public function fromRequest(Request $request): UserDevice
    {
        if (! $request->user() instanceof User) {
            throw new ApiException('AUTHENTICATION_REQUIRED', 'Authentication is required.', [], 401);
        }
        $device = $this->resolve($request->user(), $request->header('X-Device-Id'));
        $request->attributes->set('identity_device', $device);

        return $device;
    }
}

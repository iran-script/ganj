<?php

namespace App\Services\Identity;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Audit\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IdentityService
{
    public function __construct(private IdentityEvents $events, private AuditService $audit) {}

    public function register(array $input, Request $request): array
    {
        return $this->identityWrite(function () use ($input, $request): array {
            $this->validateIdentifiers($input);
            $user = User::create(array_intersect_key($input, array_flip(['name', 'email', 'mobile'])) + ['password_hash' => $input['password']]);
            $device = $this->registerDevice($user, $input, $request);
            $user->last_login_at = now();
            $user->save();
            $result = $this->issueToken($user, $device);
            $this->events->record('user.registered.v1', $user, $device, ['registration_channel' => ! empty($input['email']) ? 'email' : 'mobile']);

            return $result;
        });
    }

    public function login(array $input, Request $request): array
    {
        return DB::transaction(function () use ($input, $request): array {
            $query = User::query();
            if (! empty($input['email'])) {
                $query->whereRaw('lower(email) = ?', [mb_strtolower($input['email'])]);
            }
            if (! empty($input['mobile'])) {
                $query->where('mobile', $input['mobile']);
            }
            $user = $query->lockForUpdate()->first();
            $hash = $user?->password_hash ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            if (! Hash::check($input['password'], $hash) || ! $user) {
                throw new ApiException('AUTHENTICATION_REQUIRED', 'The credentials are invalid.', [], 401);
            }
            $user->ensureActive();
            if (Hash::needsRehash($user->password_hash)) {
                $user->password_hash = Hash::make($input['password']);
            }
            $device = $this->registerDevice($user, $input, $request);
            $user->last_login_at = now();
            $user->save();
            $result = $this->issueToken($user, $device);
            $this->events->record('user.logged_in.v1', $user, $device);

            return $result;
        }, 3);
    }

    private function registerDevice(User $user, array $input, Request $request): UserDevice
    {
        $device = $user->devices()->firstOrNew(['device_uuid' => $input['device_uuid']]);
        if ($device->revoked_at !== null) {
            throw new ApiException('AUTHORIZATION_DENIED', 'This device has been revoked.', [], 403);
        }
        $new = ! $device->exists;
        $device->fill(array_intersect_key($input, array_flip(['device_name', 'platform', 'app_version'])));
        $device->last_seen_at = now();
        $device->last_ip = $request->ip();
        $device->save();
        if ($new) {
            $this->events->record('device.registered.v1', $user, $device);
        }

        return $device;
    }

    private function issueToken(User $user, UserDevice $device): array
    {
        $plain = Str::random(64);
        $token = $user->tokens()->make(['name' => 'player-device', 'token' => hash('sha256', $plain), 'abilities' => ['*'], 'expires_at' => now()->addMinutes(config('sanctum.expiration'))]);
        $token->forceFill(['user_device_id' => $device->id])->save();

        return ['user' => $user->publicProfile(), 'device' => $device->publicProfile(), 'token' => $plain];
    }

    public function updateProfile(User $actor, array $input): User
    {
        return $this->identityWrite(function () use ($actor, $input): User {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            $user->ensureActive();
            $this->validateIdentifiers(array_replace(['mobile' => $user->mobile, 'email' => $user->email], $input), $user);
            $before = $user->only(['email', 'mobile']);
            $user->fill(array_intersect_key($input, array_flip(['name', 'email', 'mobile'])));
            $user->save();
            if ($before !== $user->only(['email', 'mobile'])) {
                $this->audit->record('user.identifiers_updated', 'user', $user->public_id, context: ['changed_fields' => array_keys(array_diff_assoc($user->only(['email', 'mobile']), $before))], actorId: $user->id);
            }

            return $user;
        });
    }

    public function logout(User $user): void
    {
        DB::transaction(function () use ($user): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $token = $user->currentAccessToken();
            $device = $user->devices()->find($token->user_device_id);
            $token->delete();
            $this->events->record('user.logged_out.v1', $user, $device);
        });
    }

    public function revoke(User $actor, string $publicId): void
    {
        DB::transaction(function () use ($actor, $publicId): void {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            $user->ensureActive();
            $device = Str::isUuid($publicId) ? $user->devices()->where('public_id', $publicId)->first() : null;
            if (! $device) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'The device was not found.', [], 404);
            }
            if ($device->revoked_at !== null) {
                return;
            }
            $device->revoked_at = now();
            $device->save();
            $user->tokens()->where('user_device_id', $device->id)->delete();
            $this->events->record('device.revoked.v1', $user, $device);
        }, 3);
    }

    public function validateIdentifiers(array $input, ?User $user = null): void
    {
        if (empty($input['mobile']) && empty($input['email'])) {
            throw ValidationException::withMessages(['email' => ['At least one login identifier is required.']]);
        }
        foreach (['email', 'mobile'] as $field) {
            if (! empty($input[$field])) {
                $query = User::query()->when($user, fn ($q) => $q->where('id', '!=', $user->id));
                $field === 'email' ? $query->whereRaw('lower(email) = ?', [mb_strtolower($input[$field])]) : $query->where($field, $input[$field]);
                if ($query->exists()) {
                    throw ValidationException::withMessages([$field => ['This login identifier is already registered.']]);
                }
            }
        }
    }

    private function identityWrite(callable $operation): mixed
    {
        try {
            return DB::transaction($operation, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['identity' => ['This login identifier is already registered.']]);
        }
    }
}

<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'mobile', 'password_hash'])]
#[Hidden(['id', 'password_hash', 'password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->public_id ??= (string) Str::uuid();
            $user->status ??= UserStatus::Active->value;
        });
    }

    protected function casts(): array
    {
        return ['password_hash' => 'hashed', 'last_login_at' => 'immutable_datetime', 'email_verified_at' => 'datetime'];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function globalRoleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function gameSessionParticipations(): HasMany
    {
        return $this->hasMany(GameSessionPlayer::class);
    }

    public function ensureActive(): void
    {
        if ($this->status !== UserStatus::Active->value) {
            throw new ApiException('AUTHORIZATION_DENIED', 'This account is not active.', ['status' => $this->status], 403);
        }
    }

    public function publicProfile(): array
    {
        return ['id' => $this->public_id, 'name' => $this->name, 'mobile' => $this->mobile, 'email' => $this->email, 'status' => $this->status, 'roles' => ['PLAYER'], 'last_login_at' => $this->last_login_at?->toISOString(), 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}

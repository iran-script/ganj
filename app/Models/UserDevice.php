<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserDevice extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'user_id', 'public_id', 'revoked_at'];

    protected $hidden = ['id', 'user_id', 'last_ip'];

    protected static function booted(): void
    {
        static::creating(function (UserDevice $device): void {
            $device->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function publicProfile(): array
    {
        return ['id' => $this->public_id, 'device_uuid' => $this->device_uuid, 'device_name' => $this->device_name, 'platform' => $this->platform, 'app_version' => $this->app_version, 'last_seen_at' => $this->last_seen_at?->toISOString(), 'revoked_at' => $this->revoked_at?->toISOString(), 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}

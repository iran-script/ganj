<?php

namespace App\Models;

use Database\Factories\TeamInviteFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'team_id', 'code_hash', 'created_by'])]
class TeamInvite extends Model
{
    /** @use HasFactory<TeamInviteFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (TeamInvite $invite): void {
            $invite->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'max_uses' => 'integer', 'uses_count' => 'integer'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function publicProfile(): array
    {
        return ['id' => $this->public_id, 'expires_at' => $this->expires_at?->toISOString(), 'max_uses' => $this->max_uses, 'uses_count' => $this->uses_count, 'revoked_at' => $this->revoked_at?->toISOString(), 'created_at' => $this->created_at?->toISOString()];
    }
}

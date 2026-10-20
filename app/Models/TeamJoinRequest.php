<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'team_id', 'team_invite_id', 'user_id', 'decided_by'])]
class TeamJoinRequest extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (TeamJoinRequest $request): void {
            $request->public_id ??= (string) Str::uuid();
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invite(): BelongsTo
    {
        return $this->belongsTo(TeamInvite::class, 'team_invite_id');
    }

    public function publicProfile(): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->expires_at?->isPast() && $this->status === 'PENDING' ? 'EXPIRED' : $this->status,
            'player' => ['id' => $this->user->public_id, 'name' => $this->user->name],
            'created_at' => $this->created_at?->toISOString(),
            'decided_at' => $this->decided_at?->toISOString(),
        ];
    }
}

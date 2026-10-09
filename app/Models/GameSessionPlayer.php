<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'game_session_id', 'game_session_team_id', 'game_session_player_id', 'team_id', 'user_id', 'active_device_id', 'device_id', 'actor_id', 'scenario_assignment_id'])]
class GameSessionPlayer extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['roster_frozen_at' => 'immutable_datetime', 'joined_runtime_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (GameSessionPlayer $player): void {
            $player->public_id ??= (string) Str::uuid();
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'game_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'active_device_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(GameSessionTeam::class, 'game_session_team_id');
    }

    public function publicProfile(): array
    {
        return ['id' => $this->public_id, 'user' => ['id' => $this->user->public_id, 'name' => $this->user->name], 'status' => $this->participation_status, 'device_bound' => $this->active_device_id !== null, 'roster_frozen_at' => $this->roster_frozen_at?->toISOString()];
    }
}

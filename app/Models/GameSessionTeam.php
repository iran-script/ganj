<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['*'])]
#[Hidden(['id', 'game_session_id', 'game_session_team_id', 'game_session_player_id', 'team_id', 'user_id', 'active_device_id', 'device_id', 'actor_id', 'scenario_assignment_id'])]
class GameSessionTeam extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime', 'removed_at' => 'immutable_datetime'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'game_session_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(GameSessionPlayer::class);
    }
}

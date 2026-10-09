<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['*'])]
#[Hidden(['id', 'game_session_id', 'game_session_team_id', 'game_session_player_id', 'team_id', 'user_id', 'active_device_id', 'device_id', 'actor_id', 'scenario_assignment_id'])]
class GameSessionRosterSnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot_json' => 'array', 'frozen_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(GameSessionTeam::class, 'game_session_team_id');
    }
}

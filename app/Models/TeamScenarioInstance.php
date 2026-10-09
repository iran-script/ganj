<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'game_session_id', 'game_session_team_id', 'team_scenario_instance_id', 'scenario_version_id', 'scenario_node_id', 'node_instance_id', 'current_node_instance_id', 'game_session_player_id', 'player_id', 'actor_id', 'request_payload', 'request_hash', 'random_seed'])]
class TeamScenarioInstance extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['pause_sources' => 'array', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'effective_elapsed_seconds' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->public_id ??= (string) Str::uuid();
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'game_session_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(GameSessionTeam::class, 'game_session_team_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ScenarioVersion::class, 'scenario_version_id');
    }
}

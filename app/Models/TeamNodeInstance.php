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
class TeamNodeInstance extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['state_json' => 'array', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'attempt_count' => 'integer', 'version_no' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->public_id ??= (string) Str::uuid();
        });
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(TeamScenarioInstance::class, 'team_scenario_instance_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(ScenarioNode::class, 'scenario_node_id');
    }
}

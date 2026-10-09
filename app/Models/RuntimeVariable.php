<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['*'])]
#[Hidden(['id', 'game_session_id', 'game_session_team_id', 'team_scenario_instance_id', 'scenario_version_id', 'scenario_node_id', 'node_instance_id', 'current_node_instance_id', 'game_session_player_id', 'player_id', 'actor_id', 'request_payload', 'request_hash', 'random_seed'])]
class RuntimeVariable extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['value' => 'json', 'updated_at' => 'immutable_datetime'];
    }
}

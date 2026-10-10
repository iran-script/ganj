<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['*'])]
#[Hidden(['id', 'game_session_id', 'team_id', 'player_id', 'node_instance_id', 'hint_definition_id', 'entitlement_consumption_id', 'idempotency_key'])]
class HintConsumption extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['penalty_score' => 'integer', 'penalty_seconds' => 'integer', 'consumed_at' => 'immutable_datetime'];
    }
}

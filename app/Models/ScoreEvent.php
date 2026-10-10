<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['*'])]
#[Hidden(['id', 'game_session_id', 'team_id', 'player_id', 'node_instance_id', 'idempotency_key'])]
class ScoreEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'delta' => 'integer'];
    }
}

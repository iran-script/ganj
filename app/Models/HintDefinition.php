<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['*'])]
#[Hidden(['id', 'scenario_node_id'])]
class HintDefinition extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['penalty_score' => 'integer', 'penalty_seconds' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}

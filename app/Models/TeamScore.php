<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['*'])]
#[Hidden(['id', 'game_session_id', 'team_id'])]
class TeamScore extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['score' => 'integer', 'effective_elapsed_seconds' => 'integer', 'hints_used' => 'integer', 'finished_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'finalized' => 'boolean'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(GameSessionTeam::class, 'team_id');
    }
}

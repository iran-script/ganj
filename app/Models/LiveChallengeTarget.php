<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['*'])]
#[Hidden(['id', 'live_challenge_id'])]
class LiveChallengeTarget extends Model
{
    protected function casts(): array
    {
        return array_fill_keys(['seen_at', 'acked_at', 'started_at', 'completed_at', 'failed_at', 'cancelled_at'], 'immutable_datetime');
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(LiveChallenge::class, 'live_challenge_id');
    }
}

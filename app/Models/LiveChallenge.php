<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'organization_id', 'game_session_id', 'template_id', 'created_by'])]
class LiveChallenge extends Model
{
    protected function casts(): array
    {
        return ['config' => 'array', 'reward_config' => 'array', 'penalty_config' => 'array', 'completion_rule' => 'array', 'starts_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'duration_seconds' => 'integer', 'priority' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $challenge): void {
            $challenge->public_id ??= (string) Str::uuid();
        });
    }

    public function targets(): HasMany
    {
        return $this->hasMany(LiveChallengeTarget::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(LiveChallengeSubmission::class);
    }
}

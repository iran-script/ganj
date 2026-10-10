<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'live_challenge_id', 'target_id', 'submitted_by', 'idempotency_key'])]
class LiveChallengeSubmission extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['evidence' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $submission): void {
            $submission->submission_id ??= (string) Str::uuid();
        });
    }
}

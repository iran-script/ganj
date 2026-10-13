<?php

namespace App\Models;

use Database\Factories\EvidenceSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'idempotency_key', 'request_hash'])]
class EvidenceSubmission extends Model
{
    /** @use HasFactory<EvidenceSubmissionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $submission): void {
            $submission->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['location_verified' => 'boolean', 'captured_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(TeamNodeInstance::class, 'team_node_instance_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(GameSessionPlayer::class, 'game_session_player_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'game_session_id');
    }
}

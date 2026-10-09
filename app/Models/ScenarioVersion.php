<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'scenario_id', 'published_by'])]
class ScenarioVersion extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (ScenarioVersion $version): void {
            $version->public_id ??= (string) Str::uuid();
            $version->revision_token ??= (string) Str::uuid();
            $version->status ??= 'DRAFT';
        });
        static::deleting(function (): void {
            throw new \LogicException('Archive versions to preserve history.');
        });
    }

    protected function casts(): array
    {
        return ['challenge_mix' => 'array', 'published_at' => 'immutable_datetime', 'version_no' => 'integer',
            'estimated_duration_seconds' => 'integer', 'estimated_distance_meters' => 'float'];
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    public function publicProfile(): array
    {
        return ['id' => $this->public_id, 'scenario_id' => $this->scenario->public_id, 'version_no' => $this->version_no,
            'status' => $this->status, 'revision_token' => $this->revision_token, 'difficulty' => $this->difficulty,
            'estimated_duration_seconds' => $this->estimated_duration_seconds, 'estimated_distance_meters' => $this->estimated_distance_meters,
            'physical_effort' => $this->physical_effort, 'challenge_mix' => $this->challenge_mix,
            'published_at' => $this->published_at?->toISOString(),
            'published_by' => $this->published_by ? User::findOrFail($this->published_by)->public_id : null,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}

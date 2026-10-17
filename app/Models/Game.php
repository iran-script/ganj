<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'organization_id'])]
class Game extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Game $game): void {
            $game->public_id ??= (string) Str::uuid();
            $game->status ??= 'DRAFT';
        });
        static::deleting(function (): void {
            throw new \LogicException('Archive games to preserve history.');
        });
    }

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime', 'pricing' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scenarioVersion(): BelongsTo
    {
        return $this->belongsTo(ScenarioVersion::class, 'scenario_version_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(GameRoom::class);
    }

    public function publicProfile(): array
    {
        $scenario = $this->scenarioVersion?->loadMissing('scenario');

        return ['id' => $this->public_id, 'organization_id' => $this->organization->public_id,
            'organization' => $this->organization->publicProfile(true),
            'name' => $this->name, 'slug' => $this->slug, 'description' => $this->description, 'city' => $this->city,
            'status' => $this->status, 'default_timezone' => $this->default_timezone,
            'pricing' => $this->pricing, 'scenario_version_id' => $scenario?->public_id,
            'scenario' => $scenario ? ['id' => $scenario->scenario->public_id, 'title' => $scenario->scenario->title,
                'status' => $scenario->status, 'version_no' => $scenario->version_no, 'version_id' => $scenario->public_id] : null,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
            'archived_at' => $this->archived_at?->toISOString()];
    }
}

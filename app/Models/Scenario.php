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
#[Hidden(['id', 'organization_id', 'created_by'])]
class Scenario extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Scenario $scenario): void {
            $scenario->public_id ??= (string) Str::uuid();
            $scenario->status ??= 'DRAFT';
        });
        static::deleting(function (): void {
            throw new \LogicException('Archive scenarios to preserve history.');
        });
    }

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ScenarioVersion::class);
    }

    public function publicProfile(): array
    {
        return ['id' => $this->public_id, 'organization_id' => $this->organization->public_id,
            'title' => $this->title, 'description' => $this->description, 'theme' => $this->theme,
            'status' => $this->status, 'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(), 'archived_at' => $this->archived_at?->toISOString()];
    }
}

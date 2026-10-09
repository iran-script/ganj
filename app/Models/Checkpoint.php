<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'organization_id', 'geom'])]
class Checkpoint extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Checkpoint $checkpoint): void {
            $checkpoint->public_id ??= (string) Str::uuid();
        });
        static::deleting(function (): void {
            throw new \LogicException('Archive checkpoints to preserve references.');
        });
    }

    protected function casts(): array
    {
        return ['config' => 'array', 'radius_meters' => 'float'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}

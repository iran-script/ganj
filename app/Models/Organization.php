<?php

namespace App\Models;

use App\Enums\OrganizationStatus;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'timezone_default'])]
#[Hidden(['id'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            $organization->public_id ??= (string) Str::uuid();
            $organization->status ??= OrganizationStatus::Active->value;
            $organization->timezone_default ??= 'UTC';
        });
        static::deleting(function (): void {
            throw new \LogicException('Organizations must be archived, not deleted.');
        });
    }

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(OrganizationSetting::class);
    }

    public function publicProfile(bool $limited = false): array
    {
        $profile = ['id' => $this->public_id, 'name' => $this->name, 'slug' => $this->slug, 'status' => $this->status, 'timezone_default' => $this->timezone_default];
        if (! $limited) {
            $profile += ['created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(), 'archived_at' => $this->archived_at?->toISOString()];
        }

        return $profile;
    }
}

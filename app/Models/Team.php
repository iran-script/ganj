<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name'])]
#[Hidden(['id', 'organization_id', 'captain_user_id', 'avatar_media_id'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Team $team): void {
            $team->public_id ??= (string) Str::uuid();
            $team->status ??= 'ACTIVE';
        });
        static::deleting(function (): void {
            throw new \LogicException('Teams must be archived, not deleted.');
        });
    }

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', 'ACTIVE');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(TeamInvite::class);
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captain_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function publicProfile(): array
    {
        $this->loadMissing(['captain', 'organization', 'activeMembers.user']);
        $count = $this->activeMembers->count();

        return ['id' => $this->public_id, 'name' => $this->name, 'status' => $this->status,
            'captain_user_id' => $this->captain->public_id,
            'captain' => ['id' => $this->captain->public_id, 'name' => $this->captain->name],
            'members' => $this->activeMembers->map(fn (TeamMember $member): array => $member->publicProfile())->values()->all(),
            'member_count' => $count, 'eligible_for_game' => $this->status === 'ACTIVE' && $count >= 4 && $count <= 8,
            'organization' => $this->organization ? ['id' => $this->organization->public_id, 'name' => $this->organization->name] : null,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(), 'archived_at' => $this->archived_at?->toISOString()];
    }
}

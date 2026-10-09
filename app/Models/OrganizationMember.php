<?php

namespace App\Models;

use Database\Factories\OrganizationMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['*'])]
#[Hidden(['id', 'organization_id', 'user_id'])]
class OrganizationMember extends Model
{
    /** @use HasFactory<OrganizationMemberFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['joined_at' => 'immutable_datetime', 'left_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new \LogicException('Membership history cannot be deleted.');
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function publicProfile(): array
    {
        return ['user_id' => $this->user->public_id, 'name' => $this->user->name, 'role' => $this->role, 'status' => $this->status, 'joined_at' => $this->joined_at->toISOString(), 'left_at' => $this->left_at?->toISOString()];
    }
}

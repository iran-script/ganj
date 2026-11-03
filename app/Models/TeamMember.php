<?php

namespace App\Models;

use Database\Factories\TeamMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['*'])]
#[Hidden(['id', 'team_id', 'user_id', 'removed_by'])]
class TeamMember extends Model
{
    /** @use HasFactory<TeamMemberFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['joined_at' => 'immutable_datetime', 'left_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function publicProfile(): array
    {
        $user = $this->user;
        $username = $user?->email ? explode('@', $user->email, 2)[0] : null;

        return [
            'user_id' => $user?->public_id,
            'name' => $user?->name ?? 'حساب حذف‌شده',
            'username' => $username,
            'email' => $user?->email,
            'account_status' => $user?->status ?? 'DELETED',
            'status' => $this->status,
            'joined_at' => $this->joined_at?->toISOString(),
        ];
    }
}

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
#[Hidden(['id', 'game_id', 'created_by'])]
class GameRoom extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (GameRoom $room): void {
            $room->public_id ??= (string) Str::uuid();
        });
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(GameRoomTeam::class);
    }
}

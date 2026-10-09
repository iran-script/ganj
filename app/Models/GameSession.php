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
#[Hidden(['id', 'organization_id', 'game_id', 'created_by'])]
class GameSession extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (GameSession $session): void {
            $session->public_id ??= (string) Str::uuid();
            $session->status ??= 'DRAFT';
        });
        static::deleting(function (): void {
            throw new \LogicException('Session history cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return array_fill_keys(['registration_opens_at', 'registration_closes_at', 'scheduled_start_at', 'started_at', 'finished_at', 'cancelled_at'], 'immutable_datetime');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(GameSessionTeam::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(GameSessionPlayer::class);
    }

    public function publicProfile(?User $viewer = null, bool $operational = false): array
    {
        $data = ['id' => $this->public_id, 'game_id' => $this->game->public_id,
            'organization_id' => $this->organization->public_id, 'status' => $this->status, 'timezone' => $this->timezone];
        foreach (['registration_opens_at', 'registration_closes_at', 'scheduled_start_at', 'started_at', 'finished_at', 'cancelled_at', 'created_at', 'updated_at'] as $field) {
            $data[$field] = $this->{$field}?->toISOString();
        }
        $data['teams'] = $this->teams()->with('team')->get()->map(function (GameSessionTeam $registration) use ($viewer, $operational): array {
            $players = $registration->players()->with('user')->get();
            $own = $viewer !== null && $players->contains('user_id', $viewer->id);

            return ['team_id' => $registration->team->public_id, 'name' => $registration->team->name, 'status' => $registration->status,
                'player_count' => $players->whereNotIn('participation_status', ['REMOVED', 'DISQUALIFIED'])->count(),
                'players' => $operational || $own ? $players->map(fn (GameSessionPlayer $player): array => $player->publicProfile())->all() : []];
        })->all();

        return $data;
    }
}

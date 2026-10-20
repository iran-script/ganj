<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'game_id', 'team_id', 'captain_user_id', 'game_room_id'])]
class GameBooking extends Model
{
    protected function casts(): array
    {
        return ['roster_snapshot' => 'array', 'pricing_snapshot' => 'array', 'payment_deadline_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (GameBooking $booking): void {
            $booking->public_id ??= (string) Str::uuid();
        });
        static::deleting(function (): void {
            throw new \LogicException('Booking and payment history cannot be deleted.');
        });
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function captain(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captain_user_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(GameRoom::class, 'game_room_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(BookingParticipant::class);
    }

    public function paymentOrders(): HasMany
    {
        return $this->hasMany(PaymentOrder::class);
    }

    public function publicProfile(User $viewer): array
    {
        $this->loadMissing(['game', 'team', 'room', 'participants.user', 'participants.paymentOrder']);

        return [
            'id' => $this->public_id,
            'game' => ['id' => $this->game->public_id, 'name' => $this->game->name],
            'team' => ['id' => $this->team->public_id, 'name' => $this->team->name],
            'room' => $this->room ? ['id' => $this->room->public_id, 'name' => $this->room->name] : null,
            'status' => $this->status,
            'payment_mode' => $this->payment_mode,
            'amount' => (int) $this->total_amount,
            'unit_amount' => (int) $this->unit_amount,
            'currency' => $this->currency,
            'payment_deadline_at' => $this->payment_deadline_at?->toISOString(),
            'participants' => $this->participants->map(fn (BookingParticipant $participant): array => [
                'user_id' => $participant->user->public_id,
                'name' => $participant->user->name,
                'amount' => (int) $participant->amount,
                'status' => $participant->status,
                'is_viewer' => $participant->user_id === $viewer->id,
                'payment_order' => $participant->paymentOrder ? [
                    'id' => $participant->paymentOrder->public_id,
                    'status' => $participant->paymentOrder->status,
                    'amount' => (int) $participant->paymentOrder->amount,
                    'currency' => $participant->paymentOrder->currency,
                ] : null,
            ])->all(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

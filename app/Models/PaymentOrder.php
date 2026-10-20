<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'game_booking_id', 'payer_user_id'])]
class PaymentOrder extends Model
{
    protected static function booted(): void
    {
        static::creating(function (PaymentOrder $order): void {
            $order->public_id ??= (string) Str::uuid();
        });
        static::deleting(function (): void {
            throw new \LogicException('Payment history cannot be deleted.');
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(GameBooking::class, 'game_booking_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }
}

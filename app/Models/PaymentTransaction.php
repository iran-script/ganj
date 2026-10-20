<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'payment_order_id'])]
class PaymentTransaction extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (PaymentTransaction $transaction): void {
            $transaction->public_id ??= (string) Str::uuid();
        });
        static::updating(function (): never {
            throw new \LogicException('Payment transaction history is append-only.');
        });
        static::deleting(function (): never {
            throw new \LogicException('Payment transaction history is append-only.');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class, 'payment_order_id');
    }
}

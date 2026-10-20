<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'payment_order_id', 'requested_by'])]
class PaymentRefund extends Model
{
    protected static function booted(): void
    {
        static::creating(function (PaymentRefund $refund): void {
            $refund->public_id ??= (string) Str::uuid();
        });
        static::deleting(function (): void {
            throw new \LogicException('Refund history cannot be deleted.');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class, 'payment_order_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}

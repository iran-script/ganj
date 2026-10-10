<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'organization_id', 'game_session_id', 'sent_by'])]
class SessionMessage extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            $message->message_id ??= (string) Str::uuid();
        });
    }
}

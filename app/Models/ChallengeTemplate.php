<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Guarded(['*'])]
#[Hidden(['id', 'organization_id'])]
class ChallengeTemplate extends Model
{
    protected function casts(): array
    {
        return ['config' => 'array', 'version_no' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $template): void {
            $template->public_id ??= (string) Str::uuid();
        });
    }
}

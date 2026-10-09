<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class Phase0SmokeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $key = 'phase0-queue-smoke') {}

    public function handle(): void
    {
        Cache::store('redis')->put($this->key, 'processed', 60);
    }
}

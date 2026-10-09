<?php

namespace App\Console\Commands;

use App\Services\Runtime\RuntimeEngine;
use Illuminate\Console\Command;

class ExpireRuntimeTimers extends Command
{
    protected $signature = 'runtime:expire-timers';

    protected $description = 'Complete expired gameplay timers under runtime command locks';

    public function handle(RuntimeEngine $engine): int
    {
        $this->info('Expired '.$engine->expireTimers().' runtime timers.');

        return self::SUCCESS;
    }
}

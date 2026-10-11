<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('runtime:expire-timers')->everyMinute()->withoutOverlapping();
Schedule::command('location:maintain-history')->hourly()->withoutOverlapping();

Schedule::command('outbox:dispatch')->everyTenSeconds()->withoutOverlapping();

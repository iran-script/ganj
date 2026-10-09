<?php

return [
    'timer_max_attempts' => (int) env('RUNTIME_TIMER_MAX_ATTEMPTS', 3),
    'timer_retry_seconds' => (int) env('RUNTIME_TIMER_RETRY_SECONDS', 60),
];

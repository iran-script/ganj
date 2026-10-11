<?php

namespace App\Services\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OutboxDispatcher
{
    public function __construct(private OutboxPublisher $publisher) {}

    /** @return array{published:int, failed:int} */
    public function dispatch(int $limit = 100): array
    {
        $counts = ['published' => 0, 'failed' => 0];
        for ($index = 0; $index < $limit; $index++) {
            $result = DB::transaction(function (): ?string {
                $event = DB::table('outbox_events')->whereIn('status', ['pending', 'failed'])
                    ->where('available_at', '<=', now())->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
                if (! $event) {
                    return null;
                }
                $attempts = $event->attempts + 1;
                try {
                    $this->publisher->publish($event);
                } catch (Throwable $exception) {
                    DB::table('outbox_events')->where('id', $event->id)->update([
                        'status' => 'failed', 'attempts' => $attempts,
                        'available_at' => now()->addSeconds(min(3600, 5 * (2 ** min($attempts - 1, 10)))),
                        'last_error' => $exception::class, 'updated_at' => now(),
                    ]);
                    Log::warning('outbox.delivery_failed', ['event_id' => $event->event_id, 'exception_type' => $exception::class, 'attempt' => $attempts]);

                    return 'failed';
                }
                DB::table('outbox_events')->where('id', $event->id)->update([
                    'status' => 'published', 'attempts' => $attempts, 'published_at' => now(), 'last_error' => null, 'updated_at' => now(),
                ]);

                return 'published';
            });
            if ($result === null) {
                break;
            }
            $counts[$result]++;
        }

        return $counts;
    }
}

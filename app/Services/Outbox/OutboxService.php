<?php

namespace App\Services\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutboxService
{
    public function record(string $topic, string $producer, string $aggregateType, string $aggregateId, array $payload, int $payloadVersion = 1, ?string $eventId = null): string
    {
        $eventId ??= (string) Str::uuid();
        DB::table('outbox_events')->insertOrIgnore(['event_id' => $eventId, 'topic' => $topic, 'producer' => $producer, 'aggregate_type' => $aggregateType, 'aggregate_id' => $aggregateId, 'payload_version' => $payloadVersion, 'payload' => json_encode($payload), 'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $eventId;
    }
}

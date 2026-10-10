<?php

namespace App\Services\Scoring;

use App\Services\Audit\AuditService;
use App\Services\Outbox\OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScoreEvents
{
    public function __construct(private OutboxService $outbox, private AuditService $audit) {}

    public function record(string $eventType, array $payload, string $aggregateId, ?int $actorId = null, ?int $organizationId = null, ?string $reason = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Scoring events require a transaction.');
        }
        $eventId = (string) Str::uuid();
        $body = ['event_id' => $eventId, 'event_type' => $eventType, 'payload_version' => 1,
            'occurred_at' => now()->toISOString(), 'request_id' => request()->attributes->get('request_id'), 'data' => $payload];
        $this->outbox->record($eventType, 'scoring', 'team_score', $aggregateId, $body, 1, $eventId);
        $this->audit->record($eventType, 'team_score', $aggregateId, $reason, $payload + ['event_id' => $eventId], $actorId, $organizationId);
    }
}

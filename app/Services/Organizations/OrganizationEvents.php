<?php

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Outbox\OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationEvents
{
    public const TYPES = ['organization.created.v1', 'organization.updated.v1', 'organization.archived.v1', 'organization.member_added.v1', 'organization.member_role_changed.v1', 'organization.member_removed.v1'];

    public function __construct(private OutboxService $outbox, private AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function record(string $type, User $actor, Organization $organization, array $data, ?string $reason = null): void
    {
        if (! in_array($type, self::TYPES, true) || DB::transactionLevel() === 0) {
            throw new \LogicException('Organization events require a registered type and an active transaction.');
        }
        $eventId = (string) Str::uuid();
        $envelope = ['event_id' => $eventId, 'event_type' => $type, 'payload_version' => 1, 'occurred_at' => now()->toISOString(), 'producer' => 'organizations', 'organization_id' => $organization->public_id, 'session_id' => null, 'team_id' => null, 'player_id' => null, 'actor' => ['type' => 'user', 'id' => $actor->public_id], 'request_id' => request()->attributes->get('request_id'), 'data' => $data + ['organization_id' => $organization->public_id, 'reason' => $reason]];
        $this->outbox->record($type, 'organizations', 'organization', $organization->public_id, $envelope, 1, $eventId);
        $this->audit->record($type, 'organization', $organization->public_id, $reason, $envelope['data'] + ['event_id' => $eventId], $actor->id, $organization->id);
    }
}

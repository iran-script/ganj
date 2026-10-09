<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;

class AuditService
{
    public function record(string $action, string $resourceType, string $resourceId, ?string $reason = null, array $context = [], ?int $actorId = null, ?int $organizationId = null): int
    {
        return DB::table('audit_events')->insertGetId(['organization_id' => $organizationId, 'actor_type' => $actorId ? 'user' : 'system', 'actor_id' => $actorId, 'action' => $action, 'resource_type' => $resourceType, 'resource_id' => $resourceId, 'reason' => $reason, 'context' => json_encode($context), 'request_id' => request()->attributes->get('request_id'), 'occurred_at' => now(), 'created_at' => now()]);
    }
}

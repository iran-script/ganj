<?php

namespace App\Services\Scenarios;

use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Outbox\OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScenarioEvents
{
    public const TYPES = ['scenario.created.v1', 'scenario.updated.v1', 'scenario.archived.v1', 'scenario_version.created.v1',
        'scenario_version.validation_started.v1', 'scenario_version.validation_passed.v1', 'scenario_version.validation_failed.v1',
        'scenario_version.published.v1', 'scenario_version.archived.v1', 'scenario_version.duplicated.v1',
        'scenario_graph.saved.v1', 'scenario_graph.validation_requested.v1', 'scenario_graph.simulation_started.v1', 'scenario_graph.simulation_completed.v1'];

    public function __construct(private OutboxService $outbox, private AuditService $audit) {}

    public function record(string $type, User $actor, Scenario $scenario, ?ScenarioVersion $version = null, array $data = [], ?string $reason = null): void
    {
        if (! in_array($type, self::TYPES, true) || DB::transactionLevel() === 0) {
            throw new \LogicException('Canonical events require a transaction.');
        }
        $event = (string) Str::uuid();
        $data += ['scenario_id' => $scenario->public_id];
        if ($version) {
            $data += ['scenario_version_id' => $version->public_id, 'version_no' => $version->version_no, 'revision_token' => $version->revision_token];
        }
        $kind = $version ? 'scenario_version' : 'scenario';
        $id = $version?->public_id ?? $scenario->public_id;
        $payload = ['event_id' => $event, 'event_type' => $type, 'payload_version' => 1, 'occurred_at' => now()->toISOString(), 'producer' => 'scenarios',
            'organization_id' => $scenario->organization->public_id, 'session_id' => null, 'team_id' => null, 'player_id' => null,
            'actor' => ['type' => 'user', 'id' => $actor->public_id], 'request_id' => request()->attributes->get('request_id'), 'data' => $data];
        $this->outbox->record($type, 'scenarios', $kind, $id, $payload, 1, $event);
        $this->audit->record($type, $kind, $id, $reason, $data + ['event_id' => $event], $actor->id, $scenario->organization_id);
    }
}

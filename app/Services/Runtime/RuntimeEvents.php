<?php

namespace App\Services\Runtime;

use App\Models\GameSessionPlayer;
use App\Models\RuntimeEvent;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Outbox\OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RuntimeEvents
{
    public const TYPES = ['instance_created', 'started', 'paused', 'resumed', 'completed', 'failed', 'cancelled', 'node_unlocked', 'node_started', 'node_submission_received', 'node_submission_rejected', 'node_completed', 'node_failed', 'node_skipped', 'variable_changed', 'role_assigned', 'role_released', 'timer_started', 'timer_paused', 'timer_resumed', 'timer_finished'];

    public function __construct(private OutboxService $outbox, private AuditService $audit) {}

    public function record(string $action, TeamScenarioInstance $instance, ?TeamNodeInstance $node = null, ?User $actor = null, ?GameSessionPlayer $player = null, array $data = [], ?string $reason = null): void
    {
        if (DB::transactionLevel() === 0 || ! in_array($action, self::TYPES, true)) {
            throw new \LogicException('Canonical runtime events require a transaction.');
        }
        $type = 'runtime.'.$action.'.v1';
        $eventId = (string) Str::uuid();
        $team = $instance->registration->team;
        $session = $instance->session;
        $data += ['team_scenario_instance_id' => $instance->public_id];
        if ($node) {
            $data += ['node_instance_id' => $node->public_id, 'scenario_node_id' => $node->node->node_key,
                'node_key' => $node->node->node_key, 'node_type' => $node->node->node_type, 'attempt_count' => $node->attempt_count];
        }
        $payload = ['event_id' => $eventId, 'event_type' => $type, 'payload_version' => 1, 'occurred_at' => now()->toISOString(),
            'producer' => 'runtime', 'organization_id' => $session->organization->public_id, 'session_id' => $session->public_id,
            'team_id' => $team->public_id, 'player_id' => $player?->public_id,
            'actor' => ['type' => $actor ? 'user' : 'system', 'id' => $actor?->public_id],
            'request_id' => request()->attributes->get('request_id'), 'data' => $data];
        $aggregate = $node ? 'node_instance' : 'team_scenario_instance';
        $aggregateId = $node?->public_id ?? $instance->public_id;
        (new RuntimeEvent)->forceFill(['event_id' => $eventId, 'aggregate_type' => $aggregate, 'aggregate_id' => $aggregateId,
            'game_session_id' => $session->id, 'team_id' => $team->id, 'player_id' => $player?->id, 'event_type' => $type,
            'payload_version' => 1, 'payload' => $payload, 'actor_type' => $actor ? 'user' : 'system', 'actor_id' => $actor?->id,
            'occurred_at' => now(), 'created_at' => now()])->save();
        $this->outbox->record($type, 'runtime', $aggregate, $aggregateId, $payload, 1, $eventId);
        $this->audit->record($type, $aggregate, $aggregateId, $reason, $data + ['event_id' => $eventId], $actor?->id, $session->organization_id);
    }
}

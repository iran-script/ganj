<?php

namespace App\Services\Runtime;

use App\Exceptions\ApiException;
use App\Models\GameSession;
use App\Models\RuntimeCommand;
use App\Models\ScenarioNode;
use App\Models\ScenarioVersion;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Services\Games\RuntimeBootstrap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RuntimeInitializer implements RuntimeBootstrap
{
    public function __construct(private RuntimeEngine $engine, private RuntimeVariables $variables, private RuntimeTimers $timers, private RuntimeEvents $events) {}

    public function bootstrap(GameSession $session, array $frozenRosters, string $commandId): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Runtime bootstrap must share the Session Start transaction.');
        }
        $session = GameSession::whereKey($session->id)->lockForUpdate()->sole();
        if (! in_array($session->status, ['READY', 'RUNNING'], true)) {
            throw new ApiException('SESSION_NOT_READY', 'Session is not ready for runtime initialization.', [], 409);
        }
        $result = [];
        foreach ($frozenRosters as $roster) {
            $registration = $session->teams()->whereHas('team', fn ($q) => $q->where('public_id', $roster['team_id']))
                ->whereIn('status', ['REGISTERED', 'READY', 'ACTIVE'])->lockForUpdate()->first();
            if (! $registration || ! Str::isUuid($roster['scenario_version_ref'] ?? '')) {
                throw new ApiException('SCENARIO_NOT_ASSIGNED', 'A pinned ScenarioVersion is required.', [], 409);
            }
            $version = ScenarioVersion::where('public_id', $roster['scenario_version_ref'])
                ->whereHas('scenario', fn ($q) => $q->where('organization_id', $session->organization_id))->first();
            $existing = TeamScenarioInstance::where('game_session_team_id', $registration->id)->first();
            if ($existing && $version && $existing->scenario_version_id === $version->id) {
                $result[$roster['team_id']] = $existing->public_id;

                continue;
            }
            if ($existing || ! $version || $version->status !== 'PUBLISHED') {
                throw new ApiException('SCENARIO_NOT_ASSIGNED', 'The assigned version must be published and immutable.', [], 409);
            }
            $nodes = ScenarioNode::where('scenario_version_id', $version->id)->orderBy('id')->get();
            if ($nodes->where('node_type', 'Start')->count() !== 1 || $nodes->where('node_type', 'Finish')->isEmpty()) {
                throw new ApiException('SESSION_NOT_READY', 'The pinned graph has no valid entry or exit.', [], 409);
            }
            $instance = new TeamScenarioInstance;
            $instance->forceFill(['game_session_id' => $session->id, 'game_session_team_id' => $registration->id, 'scenario_version_id' => $version->id,
                'status' => 'NOT_STARTED', 'random_seed' => bin2hex(random_bytes(32)), 'pause_sources' => [], 'effective_elapsed_seconds' => 0])->save();
            foreach ($nodes as $node) {
                (new TeamNodeInstance)->forceFill(['team_scenario_instance_id' => $instance->id, 'scenario_node_id' => $node->id,
                    'status' => 'LOCKED', 'attempt_count' => 0, 'version_no' => 1, 'state_json' => []])->save();
            }
            $this->variables->initialize($instance);
            $this->events->record('instance_created', $instance);
            $instance->forceFill(['status' => 'ACTIVE', 'started_at' => now()])->save();
            $this->timers->start($instance);
            $this->events->record('started', $instance);
            $start = TeamNodeInstance::where('team_scenario_instance_id', $instance->id)->where('scenario_node_id', $nodes->firstWhere('node_type', 'Start')->id)->sole();
            $this->engine->unlock($instance, $start);
            $key = 'bootstrap:'.$commandId.':'.$instance->public_id;
            (new RuntimeCommand)->forceFill(['command_id' => (string) Str::uuid(), 'game_session_id' => $session->id,
                'team_scenario_instance_id' => $instance->id, 'command_type' => 'bootstrap', 'idempotency_key' => $key,
                'request_hash' => hash('sha256', $key), 'request_payload' => ['scenario_version_id' => $version->public_id],
                'result_payload' => ['runtime_id' => $instance->public_id], 'status' => 'SUCCEEDED', 'created_at' => now(), 'completed_at' => now()])->save();
            $result[$roster['team_id']] = $instance->public_id;
        }

        return $result;
    }
}

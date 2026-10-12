<?php

namespace App\Services\Scenarios;

use App\Exceptions\ApiException;
use App\Models\Scenario;
use App\Models\ScenarioSimulationRun;
use App\Models\ScenarioValidationRun;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Idempotency\IdempotencyStore;
use App\Services\Organizations\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScenarioService
{
    public function __construct(private ScenarioScope $scope, private OrganizationScope $organizations,
        private ScenarioGraph $graphs, private GraphValidator $validator, private ScenarioEvents $events,
        private IdempotencyStore $idempotency, private NodeTypeRegistry $registry) {}

    public function execute(User $actor, string $action, array $input = [], ?string $id = null, ?string $key = null): array
    {
        return DB::transaction(function () use ($actor, $action, $input, $id, $key): array {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $input = ScenarioInput::validate($input, $action);
            $version = null;
            if ($action === 'create') {
                $context = $this->organizations->resolve($actor, $input['organization_id'], true);
                $context->authorize('update');
                $scenario = (new Scenario)->forceFill(['organization_id' => $context->organization->id, 'created_by' => $actor->id])->setRelation('organization', $context->organization);
            } elseif (in_array($action, ['update', 'archive', 'createVersion'], true)) {
                $scenario = $this->scope->resolve($actor, $id ?? '', true);
            } else {
                $version = $this->scope->version($actor, $id ?? '', true);
                $scenario = $version->scenario;
            }
            Gate::forUser($actor)->authorize($action === 'publish' ? 'publish' : 'manage', $scenario);
            if ($scenario->organization->status !== 'ACTIVE') {
                throw new ApiException('ACTION_NOT_ALLOWED', 'The organization is archived.', [], 409);
            }
            if ($this->organizations->isSuperAdmin($actor) && empty(trim($input['reason'] ?? ''))) {
                throw ValidationException::withMessages(['reason' => 'A reason is required for privileged content administration.']);
            }
            $fingerprint = '';
            if ($key !== null) {
                Validator::make(['key' => $key], ['key' => 'required|string|min:8|max:255'])->validate();
                $fingerprint = hash('sha256', json_encode([$action, $id, $this->canonical($input)], JSON_THROW_ON_ERROR));
                $key = 'scenarios:'.$actor->public_id.':'.hash('sha256', $key);
                $saved = $this->idempotency->reserve($key, $fingerprint);
                if ($saved['status'] === 'completed') {
                    return $saved['response']['data'];
                }
                if ($saved['status'] !== 'reserved') {
                    throw new ApiException('CONFLICT', 'Operation pending.', [], 409);
                }
            }
            if ($scenario->status === 'ARCHIVED' && $action !== 'archive') {
                throw new ApiException('STATE_TRANSITION_INVALID', 'The scenario is archived.', [], 409);
            }
            $result = match ($action) {
                'create','update' => $this->saveScenario($actor, $scenario, $input, $action),
                'createVersion' => $this->createVersion($actor, $scenario, $input)->publicProfile(),
                'updateVersion' => $this->updateVersion($actor, $version, $input),
                'saveGraph' => $this->saveGraph($actor, $version, $input),
                'validate' => $this->validateVersion($actor, $version, $input),
                'publish' => $this->publish($actor, $version, $input),
                'duplicate' => $this->duplicate($actor, $version, $input),
                'archive' => $this->archive($actor, $scenario, $input),
                'archiveVersion' => $this->archiveVersion($actor, $version, $input),
                'simulate' => $this->simulate($actor, $version, $input),
                default => throw new \InvalidArgumentException('Unknown scenario action.'),
            };
            if ($key !== null) {
                $this->idempotency->complete($key, $fingerprint, in_array($action, ['create', 'createVersion', 'duplicate'], true) ? 201 : 200, ['data' => $result]);
            }

            return $result;
        }, 3);
    }

    private function canonical(array $input): array
    {
        if (! array_is_list($input)) {
            ksort($input);
        }
        foreach ($input as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $input;
    }

    private function saveScenario(User $actor, Scenario $scenario, array $input, string $action): array
    {
        $scenario->forceFill(array_intersect_key($input, array_flip(['title', 'description', 'theme'])))->save();
        $this->events->record('scenario.'.($action === 'create' ? 'created' : 'updated').'.v1', $actor, $scenario, reason: $input['reason'] ?? null);

        return $scenario->publicProfile();
    }

    private function createVersion(User $actor, Scenario $scenario, array $input): ScenarioVersion
    {
        $version = (new ScenarioVersion)->forceFill($this->metadata($input) + ['scenario_id' => $scenario->id, 'version_no' => ($scenario->versions()->max('version_no') ?? 0) + 1, 'challenge_mix' => []]);
        $version->save();
        $version->setRelation('scenario', $scenario);
        $this->events->record('scenario_version.created.v1', $actor, $scenario, $version, reason: $input['reason'] ?? null);

        return $version;
    }

    private function metadata(array $input): array
    {
        return array_intersect_key($input, array_flip(['difficulty', 'estimated_duration_seconds', 'estimated_distance_meters', 'physical_effort', 'challenge_mix']));
    }

    private function draft(ScenarioVersion $version): void
    {
        if ($version->status !== 'DRAFT') {
            throw new ApiException('STATE_TRANSITION_INVALID', 'Published and archived versions are immutable.', [], 409);
        }
    }

    private function revision(ScenarioVersion $version, array $input): void
    {
        if (isset($input['revision_token']) && ! hash_equals($version->revision_token, $input['revision_token'])) {
            throw new ApiException('CONFLICT', 'The scenario revision has changed.', ['revision_token' => $version->revision_token], 409);
        }
    }

    private function updateVersion(User $actor, ScenarioVersion $version, array $input): array
    {
        $this->draft($version);
        $this->revision($version, $input);
        $version->forceFill($this->metadata($input) + ['revision_token' => (string) Str::uuid()])->save();
        $this->events->record('scenario.updated.v1', $actor, $version->scenario, $version, reason: $input['reason'] ?? null);

        return $version->publicProfile();
    }

    private function saveGraph(User $actor, ScenarioVersion $version, array $input): array
    {
        $this->draft($version);
        $this->revision($version, $input);
        $graph = $this->graphs->normalize(array_intersect_key($input, array_flip(['nodes', 'edges', 'variables', 'conditions', 'subflows'])), $version->scenario->organization_id);
        $this->graphs->replace($version, $graph);
        $version->forceFill(['revision_token' => (string) Str::uuid()])->save();
        $validation = $this->validator->validate($graph, $version->scenario->organization_id);
        $this->events->record('scenario_graph.saved.v1', $actor, $version->scenario, $version, reason: $input['reason'] ?? null);

        return ['revision_token' => $version->revision_token, 'graph' => $this->graphs->read($version), 'validation' => $validation, 'randomization' => $this->registry->randomizationContract($graph['nodes'])];
    }

    private function validateVersion(User $actor, ScenarioVersion $version, array $input): array
    {
        $this->draft($version);
        $this->revision($version, $input);
        $this->graphs->lockDependencies($version);
        $this->events->record('scenario_graph.validation_requested.v1', $actor, $version->scenario, $version);
        $this->events->record('scenario_version.validation_started.v1', $actor, $version->scenario, $version);
        $result = $this->validator->validate($this->graphs->read($version), $version->scenario->organization_id);
        (new ScenarioValidationRun)->forceFill($result + ['scenario_version_id' => $version->id, 'revision_token' => $version->revision_token, 'created_by' => $actor->id, 'created_at' => now()])->save();
        $this->events->record($result['status'] === 'PASS' ? 'scenario_version.validation_passed.v1' : 'scenario_version.validation_failed.v1', $actor, $version->scenario, $version, ['status' => $result['status']]);

        return $result + ['revision_token' => $version->revision_token];
    }

    private function publish(User $actor, ScenarioVersion $version, array $input): array
    {
        $this->revision($version, $input);
        if ($version->status === 'PUBLISHED') {
            return $version->publicProfile();
        }
        $this->draft($version);
        $latest = ScenarioValidationRun::where('scenario_version_id', $version->id)->orderByDesc('id')->first();
        if (! $latest || $latest->status !== 'PASS' || $latest->revision_token !== $version->revision_token) {
            throw new ApiException('SCENARIO_VERSION_INVALID', 'A current validation PASS is required.', [], 409);
        }
        $this->graphs->lockDependencies($version);
        $graph = $this->graphs->read($version);
        $result = $this->validator->validate($graph, $version->scenario->organization_id);
        if ($result['status'] !== 'PASS') {
            throw new ApiException('SCENARIO_VERSION_INVALID', 'Dependencies or graph are no longer valid.', $result, 409);
        }
        $version->forceFill(['status' => 'PUBLISHED', 'published_at' => now(), 'published_by' => $actor->id])->save();
        $version->scenario->forceFill(['status' => 'PUBLISHED'])->save();
        $this->events->record('scenario_version.published.v1', $actor, $version->scenario, $version,
            ['published_by' => $actor->public_id, 'dependency_versions' => $graph['subflows'], 'asset_manifest_refs' => []], $input['reason'] ?? null);

        return $version->publicProfile();
    }

    private function duplicate(User $actor, ScenarioVersion $source, array $input): array
    {
        $copy = $this->createVersion($actor, $source->scenario, $this->metadata($source->publicProfile()) + $input);
        $copy->forceFill(['challenge_mix' => $source->challenge_mix])->save();
        $this->graphs->replace($copy, $this->graphs->read($source));
        $this->events->record('scenario_version.duplicated.v1', $actor, $source->scenario, $copy, ['source_version_id' => $source->public_id], $input['reason'] ?? null);

        return $copy->publicProfile();
    }

    private function archive(User $actor, Scenario $scenario, array $input): array
    {
        if ($scenario->status !== 'ARCHIVED') {
            $scenario->forceFill(['status' => 'ARCHIVED', 'archived_at' => now()])->save();
            $this->events->record('scenario.archived.v1', $actor, $scenario, reason: $input['reason'] ?? null);
        }

        return $scenario->publicProfile();
    }

    private function archiveVersion(User $actor, ScenarioVersion $version, array $input): array
    {
        if ($version->status === 'DRAFT') {
            throw new ApiException('STATE_TRANSITION_INVALID', 'Only published versions may be archived.', [], 409);
        }
        if ($version->status !== 'ARCHIVED') {
            $version->forceFill(['status' => 'ARCHIVED'])->save();
            $this->events->record('scenario_version.archived.v1', $actor, $version->scenario, $version, reason: $input['reason'] ?? null);
        }

        return $version->publicProfile();
    }

    private function simulate(User $actor, ScenarioVersion $version, array $input): array
    {
        $this->draft($version);
        $this->revision($version, $input);
        $graph = $this->graphs->read($version);
        $this->events->record('scenario_graph.simulation_started.v1', $actor, $version->scenario, $version);
        $result = $this->validator->validate($graph, $version->scenario->organization_id);
        $adjacency = [];
        $starts = [];
        foreach ($graph['nodes'] as $node) {
            if ($node['node_type'] === 'Start') {
                $starts[] = $node['node_key'];
            }
        }
        foreach ($graph['edges'] as $edge) {
            $adjacency[$edge['from_node']][] = $edge['to_node'];
        }
        $result += ['mode' => 'STRUCTURAL_ONLY', 'revision_token' => $version->revision_token,
            'reachable_nodes' => $this->validator->reachable($starts, $adjacency),
            'branches' => array_keys(array_filter($adjacency, fn (array $targets): bool => count($targets) > 1)),
            'dead_ends' => array_values(array_map(fn (array $node): string => $node['node_key'], array_filter($graph['nodes'], fn (array $node): bool => $node['node_type'] !== 'Finish' && ! isset($adjacency[$node['node_key']])))),
            'runtime_executed' => false];
        (new ScenarioSimulationRun)->forceFill(['scenario_version_id' => $version->id, 'input_config' => $input, 'result' => $result, 'created_by' => $actor->id, 'created_at' => now()])->save();
        $this->events->record('scenario_graph.simulation_completed.v1', $actor, $version->scenario, $version, ['mode' => 'STRUCTURAL_ONLY']);

        return $result;
    }
}

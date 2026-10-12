<?php

namespace App\Services\Scenarios;

use App\Exceptions\ApiException;
use App\Models\Checkpoint;
use App\Models\ScenarioCondition;
use App\Models\ScenarioEdge;
use App\Models\ScenarioNode;
use App\Models\ScenarioSubflow;
use App\Models\ScenarioSubflowBinding;
use App\Models\ScenarioSubflowVersion;
use App\Models\ScenarioVariable;
use App\Models\ScenarioVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ScenarioGraph
{
    public function __construct(private GraphValidator $validator) {}

    public function read(ScenarioVersion $version): array
    {
        $nodes = ScenarioNode::where('scenario_version_id', $version->id)->orderBy('node_key')->get();
        $keys = $nodes->pluck('node_key', 'id');

        return [
            'nodes' => $nodes->map(fn (ScenarioNode $n): array => $n->only(['node_key', 'node_type', 'title', 'config', 'position_x', 'position_y']))->all(),
            'edges' => ScenarioEdge::where('scenario_version_id', $version->id)->orderBy('priority')->orderBy('edge_key')->get()->map(fn (ScenarioEdge $e): array => [
                'edge_key' => $e->edge_key, 'from_node' => $keys[$e->from_node_id], 'to_node' => $keys[$e->to_node_id], 'priority' => $e->priority,
                'condition_group_id' => $e->condition_group_id, 'metadata' => $e->metadata])->all(),
            'variables' => ScenarioVariable::where('scenario_version_id', $version->id)->orderBy('key')->get()->map(fn (ScenarioVariable $v): array => $v->only(['key', 'type', 'scope', 'default_value', 'writable']))->all(),
            'conditions' => ScenarioCondition::where('scenario_version_id', $version->id)->orderBy('condition_group_id')->orderBy('position')->get()->map(fn (ScenarioCondition $c): array => $c->only(['condition_group_id', 'left_operand', 'operator', 'right_operand_json', 'value_type', 'position']))->all(),
            'subflows' => ScenarioSubflowBinding::where('scenario_version_id', $version->id)->orderBy('node_id')->get()->map(function (ScenarioSubflowBinding $binding) use ($keys): array {
                $subVersion = ScenarioSubflowVersion::findOrFail($binding->subflow_version_id);

                return ['node_key' => $keys[$binding->node_id], 'subflow_id' => ScenarioSubflow::findOrFail($subVersion->scenario_subflow_id)->public_id, 'version_no' => $subVersion->version_no];
            })->all(),
        ];
    }

    public function replace(ScenarioVersion $version, array $graph): void
    {
        if (DB::transactionLevel() === 0 || $version->status !== 'DRAFT') {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Only a locked draft graph may be changed.', [], 409);
        }
        $graph = $this->normalize($graph, $version->scenario->organization_id);
        $result = $this->validator->validate($graph, $version->scenario->organization_id, false);
        if ($result['status'] !== 'PASS') {
            throw new ApiException('VALIDATION_FAILED', 'The graph is structurally invalid.', $result, 422);
        }
        foreach (['scenario_subflow_bindings', 'scenario_edges', 'scenario_conditions', 'scenario_variables', 'scenario_nodes'] as $table) {
            DB::table($table)->where('scenario_version_id', $version->id)->delete();
        }
        $keys = [];
        foreach ($graph['nodes'] as $node) {
            $record = new ScenarioNode;
            $record->forceFill($node + ['scenario_version_id' => $version->id])->save();
            $keys[$node['node_key']] = $record->id;
        }
        foreach ($graph['conditions'] ?? [] as $condition) {
            (new ScenarioCondition)->forceFill($condition + ['scenario_version_id' => $version->id, 'created_at' => now()])->save();
        }
        foreach ($graph['variables'] ?? [] as $variable) {
            (new ScenarioVariable)->forceFill($variable + ['scenario_version_id' => $version->id, 'created_at' => now()])->save();
        }
        foreach ($graph['edges'] as $edge) {
            (new ScenarioEdge)->forceFill(['scenario_version_id' => $version->id, 'edge_key' => $edge['edge_key'], 'from_node_id' => $keys[$edge['from_node']],
                'to_node_id' => $keys[$edge['to_node']], 'priority' => $edge['priority'], 'condition_group_id' => $edge['condition_group_id'] ?? null, 'metadata' => $edge['metadata'] ?? []])->save();
        }
        foreach ($graph['subflows'] ?? [] as $binding) {
            $subflow = ScenarioSubflow::where('public_id', $binding['subflow_id'])->where('organization_id', $version->scenario->organization_id)->lockForUpdate()->firstOrFail();
            $subVersion = ScenarioSubflowVersion::where('scenario_subflow_id', $subflow->id)->where('version_no', $binding['version_no'])->lockForUpdate()->firstOrFail();
            (new ScenarioSubflowBinding)->forceFill(['scenario_version_id' => $version->id, 'node_id' => $keys[$binding['node_key']], 'subflow_version_id' => $subVersion->id])->save();
        }
    }

    public function normalize(array $graph, int $organizationId): array
    {
        foreach ($graph['nodes'] as &$node) {
            if (($node['node_type'] ?? null) !== 'GPS / Location' || ! isset($node['config']['checkpoint_id'])) {
                continue;
            }
            if (isset($node['config']['latitude'], $node['config']['longitude'], $node['config']['radius_m'])) {
                continue;
            }
            $checkpoint = Checkpoint::query()->where('organization_id', $organizationId)->where('public_id', $node['config']['checkpoint_id'])
                ->where('status', 'ACTIVE')->where('type', 'POINT')
                ->selectRaw('ST_X(geom) AS longitude, ST_Y(geom) AS latitude, radius_meters')->first();
            if ($checkpoint) {
                $node['config']['latitude'] ??= (float) $checkpoint->latitude;
                $node['config']['longitude'] ??= (float) $checkpoint->longitude;
                $node['config']['radius_m'] ??= (float) $checkpoint->radius_meters;
            }
        }
        unset($node);

        return $graph;
    }

    public function lockDependencies(ScenarioVersion $version): void
    {
        $graph = $this->read($version);
        $seen = [];
        $this->lockSubflows($graph['subflows'], $version->scenario->organization_id, $seen);
    }

    private function lockSubflows(array $bindings, int $organizationId, array &$seen): void
    {
        $shape = Validator::make(['bindings' => $bindings], [
            'bindings' => 'array|list|max:1000', 'bindings.*' => 'array:node_key,subflow_id,version_no',
            'bindings.*.node_key' => 'required|string', 'bindings.*.subflow_id' => 'required|uuid',
            'bindings.*.version_no' => 'required|integer|min:1|max:2147483647',
        ]);
        if ($shape->fails()) {
            return;
        }
        usort($bindings, fn (array $a, array $b): int => [$a['subflow_id'], $a['version_no']] <=> [$b['subflow_id'], $b['version_no']]);
        foreach ($bindings as $binding) {
            $key = $binding['subflow_id'].':'.$binding['version_no'];
            if (isset($seen[$key])) {
                continue;
            }
            if (count($seen) >= 256) {
                return;
            }
            $seen[$key] = true;
            $subflow = ScenarioSubflow::where('public_id', $binding['subflow_id'])->where('organization_id', $organizationId)->lockForUpdate()->first();
            if (! $subflow) {
                continue;
            }
            $pinned = ScenarioSubflowVersion::where('scenario_subflow_id', $subflow->id)->where('version_no', $binding['version_no'])->lockForUpdate()->first();
            if ($pinned) {
                $nested = $pinned->graph_json['subflows'] ?? [];
                if (! is_array($nested)) {
                    return;
                }
                $this->lockSubflows($nested, $organizationId, $seen);
            }
        }
    }
}

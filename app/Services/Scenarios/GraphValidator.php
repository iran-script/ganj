<?php

namespace App\Services\Scenarios;

use App\Models\Checkpoint;
use App\Models\QrTrigger;
use App\Models\ScenarioSubflow;
use App\Models\ScenarioSubflowVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class GraphValidator
{
    public const TYPES = ['STRING', 'INTEGER', 'DECIMAL', 'BOOLEAN', 'DATETIME', 'JSON'];

    private array $subflowResults = [];

    private int $dependencyCount = 0;

    public function __construct(private NodeTypeRegistry $registry) {}

    /** @return array{status:string,errors:array,warnings:array} */
    public function validate(array $graph, int $organizationId, bool $semantic = true, array $visited = []): array
    {
        if ($visited === []) {
            $this->subflowResults = [];
            $this->dependencyCount = 0;
        }
        $errors = [];
        $add = function (string $code, string $path) use (&$errors): void {
            $errors[] = ['code' => $code, 'path' => $path];
        };
        $rules = [
            'nodes' => 'present|array|list|max:1000', 'nodes.*' => 'array:node_key,node_type,title,config,position_x,position_y',
            'nodes.*.node_key' => 'required|string|regex:/^[A-Za-z][A-Za-z0-9_-]{0,99}$/',
            'nodes.*.node_type' => 'required|string|max:100', 'nodes.*.title' => 'sometimes|nullable|string|max:255',
            'nodes.*.config' => 'present|array', 'nodes.*.position_x' => 'sometimes|nullable|numeric', 'nodes.*.position_y' => 'sometimes|nullable|numeric',
            'edges' => 'present|array|list|max:5000', 'edges.*' => 'array:edge_key,from_node,to_node,priority,condition_group_id,metadata',
            'edges.*.edge_key' => 'required|string|regex:/^[A-Za-z][A-Za-z0-9_-]{0,99}$/',
            'edges.*.from_node' => 'required|string|max:100', 'edges.*.to_node' => 'required|string|max:100', 'edges.*.priority' => 'required|integer|min:0|max:2147483647',
            'edges.*.condition_group_id' => 'sometimes|nullable|uuid', 'edges.*.metadata' => 'sometimes|array|max:0',
            'variables' => 'sometimes|array|list|max:500', 'variables.*' => 'array:key,type,scope,default_value,writable',
            'variables.*.key' => 'required|string|regex:/^[A-Za-z][A-Za-z0-9_]{0,99}$/', 'variables.*.type' => 'required|string|in:STRING,INTEGER,DECIMAL,BOOLEAN,DATETIME,JSON',
            'variables.*.scope' => 'required|string|in:SCENARIO,TEAM,PLAYER,NODE', 'variables.*.default_value' => 'present', 'variables.*.writable' => 'required|boolean',
            'conditions' => 'sometimes|array|list|max:2000', 'conditions.*' => 'array:condition_group_id,left_operand,operator,right_operand_json,value_type,position',
            'conditions.*.condition_group_id' => 'required|uuid', 'conditions.*.left_operand' => 'required|string|max:255',
            'conditions.*.operator' => 'required|string|in:==,!=,>,>=,<,<=,IN,NOT_IN,EXISTS,NOT_EXISTS',
            'conditions.*.right_operand_json' => 'present', 'conditions.*.value_type' => 'required|string|in:STRING,INTEGER,DECIMAL,BOOLEAN,DATETIME,JSON',
            'conditions.*.position' => 'required|integer|min:0|max:2147483647',
            'subflows' => 'sometimes|array|list|max:1000', 'subflows.*' => 'array:node_key,subflow_id,version_no',
            'subflows.*.node_key' => 'required|string|max:100', 'subflows.*.subflow_id' => 'required|uuid', 'subflows.*.version_no' => 'required|integer|min:1|max:2147483647',
        ];
        $shape = Validator::make($graph, $rules);
        if ($shape->fails()) {
            foreach (array_keys($shape->errors()->toArray()) as $path) {
                $add('INVALID_GRAPH_SHAPE', $path);
            }

            return ['status' => 'FAIL', 'errors' => $errors, 'warnings' => []];
        }
        foreach (array_diff(array_keys($graph), ['nodes', 'edges', 'variables', 'conditions', 'subflows']) as $key) {
            $add('INVALID_GRAPH_SHAPE', (string) $key);
        }
        $nodes = [];
        $edges = [];
        $variables = [];
        $groups = [];
        $positions = [];
        $adjacency = [];
        $reverse = [];
        foreach ($graph['variables'] ?? [] as $i => $variable) {
            if (isset($variables[$variable['key']])) {
                $add('DUPLICATE_VARIABLE_KEY', "variables.$i");
            }
            $variables[$variable['key']] = $variable;
            if (! $this->matches($variable['type'], $variable['default_value'])) {
                $add('INVALID_VARIABLE_DEFAULT', "variables.$i.default_value");
            }
        }
        $builtins = ['score' => 'INTEGER', 'attempt_count' => 'INTEGER', 'player.role' => 'STRING', 'team.member_count' => 'INTEGER', 'elapsed_time' => 'INTEGER',
            'team.name' => 'STRING', 'player.scanner' => 'STRING', 'checkpoint.code' => 'STRING', 'game.time_remaining' => 'INTEGER', 'random.number' => 'DECIMAL'];
        foreach ($graph['conditions'] ?? [] as $i => $condition) {
            $group = $condition['condition_group_id'];
            $groups[$group] = true;
            $position = $group.':'.$condition['position'];
            if (isset($positions[$position])) {
                $add('DUPLICATE_CONDITION_POSITION', "conditions.$i");
            }
            $positions[$position] = true;
            $operand = $condition['left_operand'];
            $type = str_starts_with($operand, 'variables.') ? ($variables[substr($operand, 10)]['type'] ?? null) : ($builtins[$operand] ?? null);
            if ($type === null) {
                $add('INVALID_VARIABLE_REFERENCE', "conditions.$i.left_operand");
            }
            if ($type !== $condition['value_type']) {
                $add('INVALID_CONDITION_TYPE', "conditions.$i.value_type");
            }
            $operator = $condition['operator'];
            $right = $condition['right_operand_json'];
            $valid = match ($operator) {
                'EXISTS','NOT_EXISTS' => $right === null,
                'IN','NOT_IN' => is_array($right) && array_is_list($right) && count($right) > 0 && count($right) <= 100 && ! array_filter($right, fn (mixed $value): bool => ! $this->matches($condition['value_type'], $value)),
                default => $this->matches($condition['value_type'], $right),
            };
            if (! $valid || (in_array($operator, ['>', '>=', '<', '<='], true) && ! in_array($type, ['INTEGER', 'DECIMAL', 'DATETIME'], true)) || ($type === 'JSON' && ! in_array($operator, ['EXISTS', 'NOT_EXISTS'], true))) {
                $add('INVALID_CONDITION', "conditions.$i");
            }
        }
        foreach ($graph['nodes'] as $i => $node) {
            $key = $node['node_key'];
            if (isset($nodes[$key])) {
                $add('DUPLICATE_NODE_KEY', "nodes.$i.node_key");
            }
            $nodes[$key] = $node;
            foreach ($this->registry->errors($node['node_type'], $node['config']) as $code) {
                $add($code, "nodes.$i.config");
            }
            if (isset($node['config']['condition_group_id']) && ! isset($groups[$node['config']['condition_group_id']])) {
                $add('INVALID_CONDITION_REFERENCE', "nodes.$i.config.condition_group_id");
            }
            $this->templates($node['config'], array_merge(array_keys($builtins), array_map(fn (string $key): string => 'variables.'.$key, array_keys($variables))), "nodes.$i.config", $add);
            if ($semantic) {
                if ($node['node_type'] === 'GPS / Location'
                    && isset($node['config']['checkpoint_id'])
                    && ! isset($node['config']['latitude'], $node['config']['longitude'], $node['config']['radius_m'])
                    && ! Checkpoint::where('public_id', $node['config']['checkpoint_id'])->where('organization_id', $organizationId)->where('status', 'ACTIVE')->exists()) {
                    $add('DEPENDENCY_NOT_READY', "nodes.$i.config");
                }
                foreach ($this->registry->definitions()[$node['node_type']]['capabilities'] ?? [] as $capability) {
                    if ($capability === 'checkpoint') {
                        if (! Checkpoint::where('public_id', $node['config']['checkpoint_id'] ?? null)->where('organization_id', $organizationId)->where('status', 'ACTIVE')->exists()) {
                            $add('DEPENDENCY_NOT_READY', "nodes.$i.config");
                        }
                    } elseif ($capability === 'qr') {
                        if (! QrTrigger::where('public_id', $node['config']['trigger_id'] ?? null)->where('organization_id', $organizationId)->where('status', 'ACTIVE')->exists()) {
                            $add('DEPENDENCY_NOT_READY', "nodes.$i.config");
                        }
                        if (isset($node['config']['checkpoint_id']) && ! Checkpoint::where('public_id', $node['config']['checkpoint_id'])->where('organization_id', $organizationId)->where('status', 'ACTIVE')->exists()) {
                            $add('DEPENDENCY_NOT_READY', "nodes.$i.config");
                        }
                    } elseif ($capability !== 'subflow') {
                        $add('DEPENDENCY_NOT_READY', "nodes.$i.config");
                    }
                }
            }
        }
        foreach ($graph['edges'] as $i => $edge) {
            if (isset($edges[$edge['edge_key']])) {
                $add('DUPLICATE_EDGE_KEY', "edges.$i.edge_key");
            }
            $edges[$edge['edge_key']] = $edge;
            if (! isset($nodes[$edge['from_node']],$nodes[$edge['to_node']])) {
                $add('DANGLING_EDGE', "edges.$i");

                continue;
            }
            if (isset($edge['condition_group_id']) && ! isset($groups[$edge['condition_group_id']])) {
                $add('INVALID_CONDITION_REFERENCE', "edges.$i.condition_group_id");
            }
            $adjacency[$edge['from_node']][] = $edge['to_node'];
            $reverse[$edge['to_node']][] = $edge['from_node'];
        }
        $bound = [];
        foreach ($graph['subflows'] ?? [] as $i => $binding) {
            $path = "subflows.$i";
            if (++$this->dependencyCount > 256) {
                $add('SUBFLOW_DEPENDENCY_LIMIT', $path);

                continue;
            }
            if (isset($bound[$binding['node_key']]) || ($nodes[$binding['node_key']]['node_type'] ?? null) !== 'Subflow') {
                $add('INVALID_SUBFLOW_BINDING', $path);
            }
            $bound[$binding['node_key']] = true;
            $version = ScenarioSubflowVersion::query()->where('version_no', $binding['version_no'])
                ->whereIn('scenario_subflow_id', ScenarioSubflow::where('public_id', $binding['subflow_id'])->where('organization_id', $organizationId)->when($semantic, fn (Builder $query): Builder => $query->where('status', '<>', 'ARCHIVED'))->select('id'))
                ->whereIn('status', $semantic ? ['PUBLISHED'] : ['PUBLISHED', 'ARCHIVED'])->first();
            if (! $version || ! is_array($version->graph_json)) {
                $add('INVALID_SUBFLOW_DEPENDENCY', $path);

                continue;
            }
            if ($semantic) {
                if (count($visited) >= 8 || in_array($version->id, $visited, true)) {
                    $add('SUBFLOW_CYCLE', $path);

                    continue;
                }
                $nested = $this->subflowResults[$version->id] ??= $this->validate($version->graph_json, $organizationId, true, [...$visited, $version->id]);
                if ($nested['status'] !== 'PASS') {
                    $add('INVALID_SUBFLOW_DEPENDENCY', $path);
                }
            }
        }
        foreach ($nodes as $key => $node) {
            if ($node['node_type'] === 'Subflow' && ! isset($bound[$key])) {
                $add('INVALID_SUBFLOW_BINDING', 'nodes.'.$key);
            }
        }
        if ($semantic) {
            $starts = array_keys(array_filter($nodes, fn (array $node): bool => $node['node_type'] === 'Start'));
            $finishes = array_keys(array_filter($nodes, fn (array $node): bool => $node['node_type'] === 'Finish'));
            if (count($starts) !== 1) {
                $add(count($starts) === 0 ? 'MISSING_START' : 'MULTIPLE_STARTS', 'nodes');
            }
            if (! $finishes) {
                $add('MISSING_FINISH', 'nodes');
            }
            foreach ($starts as $start) {
                if (isset($reverse[$start])) {
                    $add('START_HAS_INCOMING_EDGE', 'nodes.'.$start);
                }
            }
            if (count($starts) === 1) {
                $reachable = $this->reachable($starts, $adjacency);
                if (! array_intersect($finishes, $reachable)) {
                    $add('UNREACHABLE_FINISH', 'nodes');
                }
                foreach (array_diff(array_keys($nodes), $reachable) as $key) {
                    $add('UNREACHABLE_NODE', 'nodes.'.$key);
                }
            }
            $toFinish = $this->reachable($finishes, $reverse);
            foreach (array_diff(array_keys($nodes), $toFinish) as $key) {
                $add('NO_FINISH_PATH', 'nodes.'.$key);
            }
            $degree = array_fill_keys(array_keys($nodes), 0);
            foreach ($adjacency as $targets) {
                foreach ($targets as $target) {
                    $degree[$target]++;
                }
            }
            $queue = array_keys(array_filter($degree, fn (int $n): bool => $n === 0));
            $processed = 0;
            while ($queue) {
                $key = array_pop($queue);
                $processed++;
                foreach ($adjacency[$key] ?? [] as $target) {
                    $degree[$target]--;
                    if ($degree[$target] === 0) {
                        $queue[] = $target;
                    }
                }
            }
            if ($processed < count($nodes)) {
                $add('LOOP_GUARD_UNSUPPORTED', 'edges');
            }
            foreach ($nodes as $key => $node) {
                if ($node['node_type'] === 'Finish' && isset($adjacency[$key])) {
                    $add('FINISH_HAS_OUTGOING_EDGE', 'nodes.'.$key);
                }
                if (in_array($node['node_type'], ['Branch', 'Condition'], true) && count($adjacency[$key] ?? []) < 2) {
                    $add('INVALID_BRANCH', 'nodes.'.$key);
                }
            }
        }

        return ['status' => $errors ? 'FAIL' : 'PASS', 'errors' => $errors, 'warnings' => []];
    }

    public function matches(string $type, mixed $value): bool
    {
        return match ($type) {
            'STRING' => is_string($value), 'INTEGER' => is_int($value), 'DECIMAL' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'BOOLEAN' => is_bool($value), 'DATETIME' => is_string($value) && preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?(?:Z|[+-]\\d{2}:\\d{2})$/D', $value) === 1 && strtotime($value) !== false && date_parse($value)['warning_count'] === 0 && date_parse($value)['error_count'] === 0,
            'JSON' => is_array($value), default => false,
        };
    }

    /** @return list<string> */
    public function reachable(array $roots, array $adjacency): array
    {
        $seen = [];
        $queue = $roots;
        while ($queue) {
            $key = array_pop($queue);
            if (isset($seen[$key])) {
                continue;
            } $seen[$key] = true;
            foreach ($adjacency[$key] ?? [] as $target) {
                $queue[] = $target;
            }
        }

        return array_keys($seen);
    }

    private function templates(array $config, array $allowed, string $path, \Closure $add): void
    {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $this->templates($value, $allowed, $path.'.'.$key, $add);
            } elseif (is_string($value)) {
                preg_match_all('/{{(.*?)}}/s', $value, $matches);
                foreach ($matches[1] as $placeholder) {
                    if (! in_array(trim($placeholder), $allowed, true)) {
                        $add('INVALID_TEMPLATE_REFERENCE', $path.'.'.$key);
                    }
                }
                if (str_contains(preg_replace('/{{.*?}}/s', '', $value), '{{') || str_contains(preg_replace('/{{.*?}}/s', '', $value), '}}')) {
                    $add('INVALID_TEMPLATE_REFERENCE', $path.'.'.$key);
                }
            }
        }
    }
}

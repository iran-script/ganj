<?php

namespace App\Services\Scenarios;

use App\Services\Locations\LocationInput;
use App\Services\Runtime\RuntimeHandlers;
use Illuminate\Support\Facades\Validator;

class NodeTypeRegistry
{
    /** @return array<string,array{rules:array,capabilities:array,random_seed_required:bool,runtime_handler:?string}> */
    public function definitions(): array
    {
        $duration = ['duration_seconds' => 'required|integer|min:1|max:86400'];
        $puzzle = ['prompt' => 'required|string|max:10000', 'expected_answer' => 'required|string|max:1000', 'max_attempts' => 'sometimes|nullable|integer|min:1|max:1000',
            'case_sensitive' => 'required|boolean', 'trim_whitespace' => 'required|boolean', 'hints' => 'sometimes|array|list|max:3', 'hints.*' => 'string|max:2000'];
        $roles = ['roles' => 'required|array|list|min:1|max:4', 'roles.*' => 'required|string|in:SCANNER,NAVIGATOR,DECODER,AR_OPERATOR'];
        $entries = [
            'Start' => [[], []], 'Finish' => [[], []], 'Branch' => [[], []], 'Condition' => [['condition_group_id' => 'required|uuid'], []],
            'Wait' => [$duration, []], 'Timer' => [$duration + ['on_expire' => 'required|string|in:COMPLETE,FAIL', 'pause_behavior' => 'required|string|in:PAUSE,CONTINUE'], []],
            'Subflow' => [[], ['subflow']], 'Message' => [['text' => 'required|string|max:10000'], []],
            'Show Image' => [['asset_id' => 'required|uuid', 'checksum' => 'required|string|regex:/^[a-f0-9]{64}$/'], ['media']],
            'Show Video' => [['asset_id' => 'required|uuid', 'checksum' => 'required|string|regex:/^[a-f0-9]{64}$/'], ['media']],
            'GPS / Location' => [['checkpoint_id' => 'required|uuid', 'min_accuracy' => 'required|numeric|min:1|max:1000', 'required_player_count' => 'required|integer|min:1|max:8', 'dwell_time' => 'required|integer|min:0|max:86400'] + app(LocationInput::class)->policyRules(), ['checkpoint']],
            'Checkpoint' => [['checkpoint_id' => 'required|uuid'], ['checkpoint']],
            'QR' => [['trigger_id' => 'required|uuid'], ['qr']],
            'Puzzle' => [$puzzle, []], 'Password' => [$puzzle, []], 'Text Answer' => [$puzzle, []],
            'Multiple Choice' => [['prompt' => 'required|string|max:10000', 'options' => 'required|array|min:2|max:20', 'options.*' => 'required|string|max:1000',
                'correct_option_refs' => 'required|array|list|min:1|max:20', 'correct_option_refs.*' => 'required|string', 'multi_select' => 'required|boolean'], []],
            'Assign Roles' => [$roles, []], 'Swap Roles' => [$roles, []],
            'Specific Player' => [['role' => 'required|string|in:SCANNER,NAVIGATOR,DECODER,AR_OPERATOR'], []],
            'Random Player' => [[], []], 'Team Together' => [['minimum_player_count' => 'required|integer|min:1|max:8'], []],
            'Split Team' => [['groups' => 'required|array|list|min:2|max:4', 'groups.*' => 'integer|min:1|max:8'], []],
            'Add Score' => [['amount' => 'required|integer|min:1|max:1000000', 'reason' => 'required|string|max:1000'], []],
            'Subtract Score' => [['amount' => 'required|integer|min:1|max:1000000', 'reason' => 'required|string|max:1000'], []],
            'Give Hint' => [['text' => 'required|string|max:2000', 'hint_no' => 'required|integer|min:1|max:3'], []],
            'Live Challenge Trigger' => [['challenge_template_id' => 'required|uuid'], ['live_challenge']],
            'AR Challenge' => [['challenge_id' => 'required|uuid', 'scene_id' => 'required|uuid', 'fallback_policy' => 'required|string|in:REQUIRED_DEVICE,ALTERNATIVE',
                'required_capabilities' => 'required|array|list|min:1', 'required_capabilities.*' => 'string|max:100'], ['ar']],
        ];
        $result = [];
        foreach ($entries as $code => $entry) {
            $result[$code] = ['rules' => $entry[0], 'capabilities' => $entry[1], 'random_seed_required' => $code === 'Random Player', 'runtime_handler' => RuntimeHandlers::handlers()[$code] ?? null];
        }

        return $result;
    }

    /** @return list<string> */
    public function errors(string $type, array $config): array
    {
        $definition = $this->definitions()[$type] ?? null;
        if (! $definition) {
            return ['UNSUPPORTED_NODE_TYPE'];
        }
        $allowed = array_filter(array_keys($definition['rules']), fn (string $key): bool => ! str_contains($key, '.'));
        if (array_diff(array_keys($config), $allowed)) {
            return ['INVALID_NODE_CONFIG'];
        }
        if (Validator::make($config, $definition['rules'])->fails()) {
            return ['INVALID_NODE_CONFIG'];
        }
        if ($type === 'Multiple Choice') {
            if (array_diff($config['correct_option_refs'], array_keys($config['options'])) || (! $config['multi_select'] && count($config['correct_option_refs']) !== 1)) {
                return ['INVALID_NODE_CONFIG'];
            }
        }
        if ($type === 'Split Team' && array_sum($config['groups']) > 8) {
            return ['INVALID_NODE_CONFIG'];
        }

        return [];
    }

    public function randomizationContract(array $nodes): array
    {
        $required = false;
        foreach ($nodes as $node) {
            $required = $required || ($this->definitions()[$node['node_type']]['random_seed_required'] ?? false);
        }

        return ['seed_required' => $required, 'seed_owner' => 'TeamScenarioInstance', 'seed_frozen_at' => 'instance_creation', 'runtime_handler_available' => true];
    }
}

<?php

namespace App\Services\Runtime;

use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\PlayerRoleAssignment;
use App\Models\ScenarioCondition;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Services\Scenarios\GraphValidator;
use Carbon\CarbonImmutable;

class RuntimeConditions
{
    public function __construct(private RuntimeVariables $variables, private GraphValidator $types, private RuntimeTimers $timers) {}

    public function evaluate(TeamScenarioInstance $instance, string $group, ?TeamNodeInstance $node = null, ?GameSessionPlayer $player = null): bool
    {
        $conditions = ScenarioCondition::where('scenario_version_id', $instance->scenario_version_id)->where('condition_group_id', $group)->orderBy('position')->get();
        if ($conditions->isEmpty()) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'The pinned condition group is unavailable.', [], 409);
        }
        foreach ($conditions as $condition) {
            $left = $this->operand($instance, $condition->left_operand, $node, $player);
            $right = $condition->right_operand_json;
            $operator = $condition->operator;
            if (in_array($operator, ['EXISTS', 'NOT_EXISTS'], true)) {
                $passed = $operator === 'EXISTS' ? $left !== null : $left === null;
            } elseif (! $this->types->matches($condition->value_type, $left)) {
                $passed = false;
            } else {
                if ($condition->value_type === 'DATETIME') {
                    $left = CarbonImmutable::parse($left)->timestamp;
                    $right = is_array($right) ? array_map(fn (string $v): int => CarbonImmutable::parse($v)->timestamp, $right) : CarbonImmutable::parse($right)->timestamp;
                } elseif ($condition->value_type === 'DECIMAL') {
                    $left = (float) $left;
                    $right = is_array($right) ? array_map(fn (mixed $v): float => (float) $v, $right) : (float) $right;
                }
                $passed = match ($operator) {
                    '==' => $left === $right, '!=' => $left !== $right,
                    '>' => $left > $right, '>=' => $left >= $right, '<' => $left < $right, '<=' => $left <= $right,
                    'IN' => in_array($left, $right, true), 'NOT_IN' => ! in_array($left, $right, true),
                    default => false,
                };
            }
            if (! $passed) {
                return false;
            }
        }

        return true;
    }

    public function random(TeamScenarioInstance $instance, string $context): float
    {
        return hexdec(substr(hash_hmac('sha256', $context, $instance->random_seed), 0, 13)) / 4503599627370496;
    }

    private function operand(TeamScenarioInstance $instance, string $operand, ?TeamNodeInstance $node, ?GameSessionPlayer $player): mixed
    {
        if (str_starts_with($operand, 'variables.')) {
            return $this->variables->read($instance, substr($operand, 10), $player, $node);
        }

        return match ($operand) {
            'attempt_count' => $node?->attempt_count ?? 0,
            'team.member_count' => $instance->registration->players()->whereNotIn('participation_status', ['REMOVED', 'DISQUALIFIED'])->count(),
            'team.name' => $instance->registration->team->name,
            'elapsed_time' => $this->timers->instanceElapsed($instance),
            'player.role' => $player ? PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)->where('game_session_player_id', $player->id)->whereNull('released_at')->value('role_code') : null,
            'player.scanner' => PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)->where('role_code', 'SCANNER')->whereNull('released_at')->first()?->public_id,
            'random.number' => $this->random($instance, 'condition:'.($node?->node->node_key ?? 'instance')),
            default => throw new ApiException('ACTION_NOT_ALLOWED', 'The condition requires an unavailable domain.', [], 409),
        };
    }
}

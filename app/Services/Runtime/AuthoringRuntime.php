<?php

namespace App\Services\Runtime;

use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\LiveChallengeTarget;
use App\Models\RuntimeTimer;
use App\Models\TeamNodeInstance;
use App\Services\Media\SubmissionService;
use App\Services\Scoring\ScoringService;
use Carbon\CarbonImmutable;

class AuthoringRuntime
{
    public function __construct(private RuntimeVariables $variables, private RuntimeConditions $conditions, private RuntimeRoles $roles, private RuntimeTimers $timers, private BarrierEvaluator $barriers, private ScoringService $scoring) {}

    public static function compare(mixed $left, string $operator, mixed $right): bool
    {
        if ($operator === 'EXISTS' || $operator === 'NOT_EXISTS') {
            return $operator === 'EXISTS' ? $left !== null : $left === null;
        }
        if (get_debug_type($left) !== get_debug_type($right)) {
            return false;
        }

        return match ($operator) {
            '==' => $left === $right, '!=' => $left !== $right,
            '>' => $left > $right, '>=' => $left >= $right, '<' => $left < $right, '<=' => $left <= $right,
            default => false,
        };
    }

    public function automatic(TeamNodeInstance $node, ?GameSessionPlayer $player): void
    {
        $config = $node->node->config;
        $instance = $node->instance;
        $outcome = 'SUCCESS';
        switch ($node->node->node_type) {
            case 'SetVariable':
                $this->variables->write($instance, $config['variable'], $config['value']);
                break;
            case 'RandomPlayer':
                $players = $instance->registration->players()->whereIn('participation_status', ['READY', 'ACTIVE'])->orderBy('public_id')->get();
                if ($players->isEmpty()) {
                    throw new ApiException('ACTION_NOT_ALLOWED', 'No eligible player is available.', [], 409);
                }
                $index = (int) floor($this->conditions->random($instance, 'player:'.$node->node->node_key) * $players->count());
                $selected = $players[$index]->public_id;
                $this->variables->write($instance, $config['variable'], $selected);
                $node->forceFill(['state_json' => array_replace($node->state_json ?? [], ['selected_player_id' => $selected])])->save();
                break;
            case 'CheckVariable':
                $outcome = self::compare($this->variables->read($instance, $config['variable'], null, null), $config['operator'], $config['value']) ? 'TRUE' : 'FALSE';
                break;
            case 'Condition':
                $outcome = self::compare($this->conditions->operand($instance, $config['operand'], $node, $player), $config['operator'], $config['value']) ? 'TRUE' : 'FALSE';
                break;
            case 'RoleCheck':
                $outcome = $player && $this->roles->roleFor($instance, $player) === $config['role'] ? 'TRUE' : 'FALSE';
                break;
            case 'AssignRole':
                $this->roles->rotate($instance, $node);
                break;
            case 'Score':
                $amount = $config['amount'] * ($config['operation'] === 'SUBTRACT' ? -1 : 1);
                $this->scoring->apply($instance, $player, $node, $amount, 'NODE_COMPLETION', $config['reason'], 'authoring-score:'.$node->public_id);
                break;
            case 'Time':
                $outcome = self::compare($this->timers->instanceElapsed($instance), $config['operator'], $config['value']) ? 'TRUE' : 'FALSE';
                break;
        }
        $this->outcome($node, $outcome);
    }

    public function outcome(TeamNodeInstance $node, string $outcome): void
    {
        $node->forceFill(['state_json' => array_replace($node->state_json ?? [], ['outcome' => $outcome])])->save();
    }

    public function execute(TeamNodeInstance $node, ?GameSessionPlayer $player, array $input, string $commandId): array
    {
        $config = $node->node->config;
        $type = $node->node->node_type;
        if (in_array($type, ['TeamTogether', 'Barrier', 'SplitInformation'], true)) {
            $result = $this->barriers->evaluate($node, $player, $input);
            foreach ($config['required_variables'] ?? [] as $variable) {
                $result['complete'] = $result['complete'] && $this->variables->read($node->instance, $variable, null, null) === true;
            }

            return $result;
        }
        if ($type === 'Time') {
            if ($config['mode'] === 'TIME_WINDOW') {
                $inside = now()->betweenIncluded(CarbonImmutable::parse($config['starts_at']), CarbonImmutable::parse($config['ends_at']));

                return ['accepted' => $inside, 'complete' => $inside, 'result' => $inside ? 'SUCCESS' : 'WAITING'];
            }
            $timer = RuntimeTimer::where('node_instance_id', $node->id)->sole();
            $elapsed = $this->timers->elapsed($timer);
            $complete = $config['mode'] === 'DELAY' ? $elapsed >= $config['duration_seconds'] : ($input['acknowledged'] ?? false) && $elapsed < $config['duration_seconds'];

            return ['accepted' => (bool) $complete, 'complete' => (bool) $complete];
        }
        if ($type === 'LiveChallenge') {
            $complete = LiveChallengeTarget::where('target_type', 'TEAM')->where('target_id', $node->instance->registration->team->public_id)
                ->where('status', 'COMPLETED')->whereHas('challenge', fn ($query) => $query->where('game_session_id', $node->instance->game_session_id)
                ->where('type', $config['category'])->where('created_at', '>=', $node->started_at))->exists();

            return ['accepted' => true, 'complete' => $complete, 'result' => $complete ? 'SUCCESS' : 'WAITING'];
        }
        if ($type === 'MediaReveal') {
            $complete = ! $config['require_acknowledgement'] || ($input['acknowledged'] ?? false) === true;

            return ['accepted' => $complete, 'complete' => $complete];
        }
        if (in_array($type, ['PhotoSubmission', 'AudioSubmission', 'FileSubmission'], true)) {
            return app(SubmissionService::class)->complete($node, $player, $input);
        }

        throw new ApiException('ACTION_NOT_ALLOWED', 'This node is controlled by the runtime.', [], 403);
    }
}

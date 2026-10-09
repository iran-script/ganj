<?php

namespace App\Services\Runtime;

use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\RuntimeEvent;
use App\Models\RuntimeTimer;
use App\Models\TeamNodeInstance;
use App\Services\Scenarios\NodeTypeRegistry;

class RuntimeHandlers
{
    /** @return array<string,string> */
    public static function handlers(): array
    {
        return ['Start' => 'automatic', 'Finish' => 'acknowledge', 'Message' => 'acknowledge',
            'Branch' => 'automatic', 'Condition' => 'automatic', 'Assign Roles' => 'automatic', 'Swap Roles' => 'automatic',
            'Split Team' => 'automatic', 'Random Player' => 'acknowledge', 'Specific Player' => 'acknowledge',
            'Puzzle' => 'answer', 'Password' => 'answer', 'Text Answer' => 'answer', 'Multiple Choice' => 'choice',
            'Timer' => 'timer', 'Wait' => 'timer', 'Team Together' => 'barrier',
            'GPS / Location' => LocationVerification::class, 'Checkpoint' => LocationVerification::class,
            'QR' => QRVerification::class, 'AR Challenge' => ARVerification::class,
            'Add Score' => ScoreAction::class, 'Subtract Score' => ScoreAction::class,
            'Give Hint' => HintAction::class, 'Live Challenge Trigger' => LiveChallengeTrigger::class];
    }

    public function __construct(private RuntimeTimers $timers) {}

    public function submit(TeamNodeInstance $node, ?GameSessionPlayer $player, array $input, string $commandId): array
    {
        $handler = app(NodeTypeRegistry::class)->definitions()[$node->node->node_type]['runtime_handler'] ?? null;
        if (! $handler) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'This node handler is not available.', [], 409);
        }
        if (str_contains($handler, '\\')) {
            return app($handler)->execute($node, $player, $input, $commandId);
        }

        return $this->{$handler}($node, $player, $input);
    }

    public function automatic(TeamNodeInstance $node, ?GameSessionPlayer $player, array $input): array
    {
        throw new ApiException('ACTION_NOT_ALLOWED', 'Control nodes are engine-owned.', [], 403);
    }

    public function acknowledge(TeamNodeInstance $node, ?GameSessionPlayer $player, array $input): array
    {
        $accepted = ($input['acknowledged'] ?? null) === true;

        return ['accepted' => $accepted, 'complete' => $accepted];
    }

    public function answer(TeamNodeInstance $node, ?GameSessionPlayer $player, array $input): array
    {
        $config = $node->node->config;
        if (isset($config['max_attempts']) && $node->attempt_count >= $config['max_attempts']) {
            return ['accepted' => false, 'complete' => false, 'result' => 'EXPIRED'];
        }
        $value = $input['answer'] ?? null;
        $expected = $config['expected_answer'];
        if (is_string($value)) {
            if ($config['trim_whitespace']) {
                $value = trim($value);
                $expected = trim($expected);
            }
            if (! $config['case_sensitive']) {
                $value = mb_strtolower($value);
                $expected = mb_strtolower($expected);
            }
        }
        $accepted = is_string($value) && hash_equals($expected, $value);

        return ['accepted' => $accepted, 'complete' => $accepted];
    }

    public function choice(TeamNodeInstance $node, ?GameSessionPlayer $player, array $input): array
    {
        $selected = $input['selected_option_refs'] ?? [];
        $expected = $node->node->config['correct_option_refs'];
        sort($selected);
        sort($expected);
        $accepted = $selected === $expected;

        return ['accepted' => $accepted, 'complete' => $accepted];
    }

    public function timer(TeamNodeInstance $node, ?GameSessionPlayer $player, array $input): array
    {
        $timer = RuntimeTimer::where('node_instance_id', $node->id)->sole();
        $expired = $this->timers->elapsed($timer) >= $timer->duration_seconds;

        return ['accepted' => $expired, 'complete' => $expired && ($node->node->config['on_expire'] ?? 'COMPLETE') === 'COMPLETE',
            'fail' => $expired && ($node->node->config['on_expire'] ?? 'COMPLETE') === 'FAIL'];
    }

    public function barrier(TeamNodeInstance $node, ?GameSessionPlayer $player, array $input): array
    {
        if (! $player || ($input['acknowledged'] ?? null) !== true) {
            return ['accepted' => false, 'complete' => false];
        }
        $contributions = RuntimeEvent::where('aggregate_id', $node->public_id)->where('event_type', 'runtime.node_submission_received.v1')
            ->where('payload->data->contribution', true)->pluck('player_id');
        $duplicate = $contributions->contains($player->id);
        $count = $contributions->count() + ($duplicate ? 0 : 1);

        return ['accepted' => true, 'complete' => $count >= $node->node->config['minimum_player_count'],
            'contribution' => ! $duplicate, 'result' => $duplicate ? 'DUPLICATE' : 'ACCEPTED', 'progress' => $count];
    }
}

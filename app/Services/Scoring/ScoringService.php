<?php

namespace App\Services\Scoring;

use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\ScoreEvent;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Models\TeamScore;
use App\Services\Runtime\ScoreAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScoringService implements ScoreAction
{
    public const SOURCES = ['NODE_COMPLETION', 'TIME_BONUS', 'FIRST_ATTEMPT_BONUS', 'NO_HINT_BONUS', 'HINT_PENALTY', 'LIVE_CHALLENGE_REWARD', 'LIVE_CHALLENGE_PENALTY', 'MANUAL_ADJUSTMENT', 'ANTI_CHEAT_PENALTY'];

    public function __construct(private ScoreEvents $events) {}

    public function execute(TeamNodeInstance $node, ?GameSessionPlayer $player, array $evidence, string $commandId): array
    {
        $config = $node->node->config;
        $amount = (int) ($config['amount'] ?? 0);
        if ($amount < 1 || ! $player) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'The score action is not configured.', [], 409);
        }
        $delta = $node->node->node_type === 'Subtract Score' ? -$amount : $amount;
        $this->apply($node->instance, $player, $node, $delta, 'NODE_COMPLETION', $config['reason'] ?? 'Scenario score action', $commandId, ['configured_amount' => $amount]);

        return ['accepted' => true, 'complete' => true, 'score_delta' => $delta];
    }

    public function recordCompletion(TeamScenarioInstance $instance, TeamNodeInstance $node, ?GameSessionPlayer $player): void
    {
        $score = $node->node->config['score'] ?? [];
        if (! is_array($score) || $score === []) {
            return;
        }
        $attempt = $node->attempt_count;
        $base = (int) ($score['base'] ?? $score['completion'] ?? 0);
        $this->apply($instance, $player, $node, $base, 'NODE_COMPLETION', 'Node completion', 'completion:'.$node->public_id, ['attempt_count' => $attempt]);
        if ($attempt === 1) {
            $this->apply($instance, $player, $node, (int) ($score['first_attempt_bonus'] ?? 0), 'FIRST_ATTEMPT_BONUS', 'First accepted attempt', 'first:'.$node->public_id, ['attempt_count' => $attempt]);
        }
        if ((int) ($node->state_json['hints_used'] ?? 0) === 0) {
            $this->apply($instance, $player, $node, (int) ($score['no_hint_bonus'] ?? 0), 'NO_HINT_BONUS', 'Completed without hints', 'no-hint:'.$node->public_id);
        }
        $time = $score['time_bonus'] ?? [];
        if (is_array($time) && isset($time['under_seconds'], $time['amount'])) {
            $elapsed = $node->started_at ? max(0, now()->timestamp - $node->started_at->timestamp) : 0;
            if ($elapsed <= (int) $time['under_seconds']) {
                $this->apply($instance, $player, $node, (int) $time['amount'], 'TIME_BONUS', 'Time bonus', 'time:'.$node->public_id, ['effective_elapsed_seconds' => $elapsed]);
            }
        }
    }

    public function apply(TeamScenarioInstance $instance, ?GameSessionPlayer $player, ?TeamNodeInstance $node, int $delta, string $source, string $reason, string $idempotencyKey, array $metadata = [], ?int $actorId = null, ?string $auditReason = null): ScoreEvent
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'The score source is not permitted.', [], 422);
        }

        return DB::transaction(function () use ($instance, $player, $node, $delta, $source, $reason, $idempotencyKey, $metadata, $actorId, $auditReason): ScoreEvent {
            $team = $instance->registration;
            $score = $this->scoreFor($instance->game_session_id, $team->id);
            $event = ScoreEvent::where('idempotency_key', $idempotencyKey)->first();
            if ($event) {
                if ($event->game_session_id !== $instance->game_session_id || $event->team_id !== $team->id
                    || $event->delta !== $delta || $event->source_type !== $source || $event->node_instance_id !== $node?->id
                    || $event->player_id !== $player?->id || $event->reason_code !== $reason) {
                    throw new ApiException('IDEMPOTENCY_CONFLICT', 'The score key belongs to a different mutation.', [], 409);
                }

                return $event;
            }
            if ($score->finalized && $source !== 'MANUAL_ADJUSTMENT') {
                throw new ApiException('STATE_TRANSITION_INVALID', 'Finished scores cannot be changed by gameplay.', [], 409);
            }
            $event = new ScoreEvent;
            $event->forceFill(['event_id' => (string) Str::uuid(), 'game_session_id' => $instance->session->id, 'team_id' => $team->id,
                'player_id' => $player?->id, 'node_instance_id' => $node?->id, 'source_type' => $source, 'source_id' => $node?->public_id,
                'delta' => $delta, 'reason_code' => $reason, 'metadata' => $metadata, 'idempotency_key' => $idempotencyKey,
                'occurred_at' => now(), 'created_at' => now()])->save();
            $score->forceFill(['score' => $score->score + $delta, 'updated_at' => now()])->save();
            $payload = ['session_id' => $instance->session->public_id, 'team_id' => $team->team->public_id, 'previous_score' => $score->score - $delta,
                'delta' => $delta, 'new_score' => $score->score, 'source_type' => $source, 'source_id' => $node?->public_id,
                'effective_elapsed_seconds' => $score->effective_elapsed_seconds, 'hints_used' => $score->hints_used];
            $this->events->record('score.event_recorded.v1', $payload, $team->team->public_id, $actorId, $instance->session->organization_id, $auditReason);
            $this->events->record('score.team_changed.v1', $payload, $team->team->public_id, $actorId, $instance->session->organization_id, $auditReason);
            $this->events->record('leaderboard.changed.v1', ['session_id' => $instance->session->public_id, 'team_id' => $team->team->public_id,
                'score' => $score->score, 'effective_elapsed_seconds' => $score->effective_elapsed_seconds, 'hints_used' => $score->hints_used], $team->team->public_id, $actorId, $instance->session->organization_id, $auditReason);

            return $event;
        });
    }

    public function finalize(TeamScenarioInstance $instance): TeamScore
    {
        return DB::transaction(function () use ($instance): TeamScore {
            $score = $this->scoreFor($instance->game_session_id, $instance->game_session_team_id);
            if ($score->finalized) {
                return $score;
            }
            $score->forceFill(['effective_elapsed_seconds' => $instance->effective_elapsed_seconds, 'finished_at' => now(), 'finalized' => true, 'updated_at' => now()])->save();
            $this->events->record('score.finalized.v1', ['session_id' => $instance->session->public_id, 'team_id' => $instance->registration->team->public_id, 'score' => $score->score], $instance->registration->team->public_id, null, $instance->session->organization_id);

            return $score;
        });
    }

    public function rebuild(int $sessionId, int $teamId): TeamScore
    {
        return DB::transaction(function () use ($sessionId, $teamId): TeamScore {
            $score = $this->scoreFor($sessionId, $teamId);
            $sum = (int) ScoreEvent::where('game_session_id', $sessionId)->where('team_id', $teamId)->sum('delta');
            $score->forceFill(['score' => $sum, 'updated_at' => now()])->save();

            return $score;
        });
    }

    /** Call within the transaction that mutates the score projection. */
    public function scoreFor(int $sessionId, int $teamId): TeamScore
    {
        TeamScore::query()->insertOrIgnore(['game_session_id' => $sessionId, 'team_id' => $teamId, 'score' => 0, 'effective_elapsed_seconds' => 0, 'hints_used' => 0, 'updated_at' => now()]);

        return TeamScore::where(['game_session_id' => $sessionId, 'team_id' => $teamId])->lockForUpdate()->sole();
    }
}

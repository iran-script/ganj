<?php

namespace App\Services\GameMaster;

use App\Exceptions\ApiException;
use App\Models\GameSession;
use App\Models\GameSessionPlayer;
use App\Models\LiveChallenge;
use App\Models\LiveChallengeSubmission;
use App\Models\LiveChallengeTarget;
use App\Models\PlayerRoleAssignment;
use App\Models\SessionMessage;
use App\Models\TeamScenarioInstance;
use App\Models\TeamScore;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Games\GameScope;
use App\Services\Games\SessionService;
use App\Services\Idempotency\IdempotencyStore;
use App\Services\Outbox\OutboxService;
use App\Services\Runtime\RuntimeEngine;
use App\Services\Runtime\RuntimeRoles;
use App\Services\Scoring\ScoringService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LiveChallengeService
{
    public function __construct(
        private GameScope $scope,
        private IdempotencyStore $idempotency,
        private OutboxService $outbox,
        private AuditService $audit,
        private ScoringService $scoring,
        private RuntimeEngine $runtime,
        private SessionService $sessions,
        private RuntimeRoles $roles,
    ) {}

    public function controlRoom(User $actor, string $sessionId): array
    {
        $session = $this->operatorSession($actor, $sessionId);
        $teams = $session->teams()->with('team')->get()->map(function ($registration) use ($actor, $session): array {
            $players = $registration->players()->with(['user', 'device'])->get();
            $instance = TeamScenarioInstance::where('game_session_team_id', $registration->id)->first();
            $score = TeamScore::where(['game_session_id' => $session->id, 'team_id' => $registration->id])->first();

            $runtime = $instance ? $this->runtime->read($actor, $session->public_id, $registration->team->public_id) : null;

            return ['team_id' => $registration->team->public_id, 'name' => $registration->team->name, 'status' => $registration->status,
                'players' => $players->map(fn (GameSessionPlayer $player): array => [
                    'id' => $player->public_id, 'name' => $player->user->name, 'status' => $player->participation_status,
                    'presence' => $player->last_seen_at?->toISOString(), 'device_bound' => $player->active_device_id !== null,
                    'device_last_seen_at' => $player->device?->last_seen_at?->toISOString(),
                ])->all(), 'score' => $score ? ['score' => $score->score, 'effective_elapsed_seconds' => $score->effective_elapsed_seconds,
                    'hints_used' => $score->hints_used, 'finished_at' => $score->finished_at?->toISOString()] : null, 'runtime' => $runtime];
        })->all();

        return ['session' => $session->publicProfile($actor, true), 'teams' => $teams,
            'live_challenges' => $this->list($actor, $sessionId),
            'timeline' => DB::table('audit_events')->where('resource_type', 'game_session')->where('resource_id', $session->public_id)->orderByDesc('occurred_at')->limit(50)->get()->map(fn ($event): array => [
                'event_type' => $event->action, 'reason' => $event->reason, 'occurred_at' => $event->occurred_at,
            ])->all()];
    }

    public function list(User $actor, string $sessionId): array
    {
        $session = $this->scope->resolve($actor, $sessionId, true);
        $operator = $this->scope->operator($actor, $session);
        $player = $session->players()->where('user_id', $actor->id)->first();
        if (! $operator && ! $player) {
            throw $this->notFound();
        }
        $query = LiveChallenge::where('game_session_id', $session->id)->with('targets')->orderByDesc('priority')->orderByDesc('created_at');
        if (! $operator) {
            $teamId = (string) $player->game_session_team_id;
            $query->whereHas('targets', function (Builder $target) use ($player, $session): void {
                $target->where(fn (Builder $q): Builder => $q->where(['target_type' => 'SESSION', 'target_id' => $session->public_id])
                    ->orWhere(['target_type' => 'PLAYER', 'target_id' => $player->public_id])
                    ->orWhere(['target_type' => 'TEAM', 'target_id' => $player->registration->team->public_id]));
            });
        }

        return $query->get()->map(fn (LiveChallenge $challenge): array => $this->profile($challenge, $operator))->all();
    }

    public function show(User $actor, string $challengeId): array
    {
        $challenge = LiveChallenge::where('public_id', $challengeId)->with('targets')->first();
        if (! $challenge) {
            throw $this->notFound();
        }
        $session = $this->scope->resolve($actor, GameSession::whereKey($challenge->game_session_id)->value('public_id'), true);
        $operator = $this->scope->operator($actor, $session);
        $target = $this->targetForActor($challenge, $actor, $session);
        if (! $operator && ! $target) {
            throw $this->notFound();
        }

        return $this->profile($challenge, $operator, $target);
    }

    public function create(User $actor, string $sessionId, array $input, string $key): array
    {
        $session = $this->operatorSession($actor, $sessionId);
        $this->requiredKey($key);
        $fingerprint = $this->fingerprint('create', $session->public_id, $input);
        $saved = $this->idempotency->reserve('live-challenge:'.hash('sha256', $key), $fingerprint);
        if ($saved['status'] === 'completed') {
            return $saved['response']['data'];
        }
        $result = DB::transaction(function () use ($actor, $session, $input): array {
            $this->validateCreate($input);
            $type = $input['type'];
            if (! in_array($type, ['MESSAGE', 'TEAM_OBJECTIVE'], true)) {
                throw new ApiException('ACTION_NOT_ALLOWED', 'The Pilot supports message/team objectives only.', [], 409);
            }
            if (($input['fairness_class'] ?? 'SIDE_OBJECTIVE') === 'COMPETITIVE') {
                throw new ApiException('ACTION_NOT_ALLOWED', 'Competitive live rewards require an approved fairness policy.', [], 409);
            }
            $challenge = new LiveChallenge;
            $challenge->forceFill(['organization_id' => $session->organization_id, 'game_session_id' => $session->id, 'type' => $type,
                'priority' => (int) ($input['priority'] ?? 0), 'status' => 'ACTIVE', 'starts_at' => now(),
                'expires_at' => isset($input['duration_seconds']) ? now()->addSeconds((int) $input['duration_seconds']) : ($input['expires_at'] ?? null),
                'duration_seconds' => $input['duration_seconds'] ?? null, 'config' => ['message' => $input['message'] ?? $input['objective'] ?? ''],
                'reward_config' => $input['reward'] ?? [], 'penalty_config' => $input['penalty'] ?? [],
                'interrupt_policy' => $input['interrupt_policy'] ?? 'PARALLEL', 'completion_rule' => ['type' => 'ACKNOWLEDGEMENT'],
                'fairness_class' => $input['fairness_class'] ?? 'SIDE_OBJECTIVE', 'created_by' => $actor->id])->save();
            foreach ($this->resolveTargets($session, $input['targets']) as $target) {
                $record = new LiveChallengeTarget;
                $record->forceFill(['target_type' => $target['type'], 'target_id' => $target['id'], 'status' => 'SENT']);
                $challenge->targets()->save($record);
            }
            $this->event('live_challenge.created.v1', $challenge, $actor, ['target_count' => $challenge->targets()->count()]);

            return $this->profile($challenge->fresh('targets'), true);
        });
        $response = ['data' => $result];
        $this->idempotency->complete('live-challenge:'.hash('sha256', $key), $fingerprint, 201, $response);

        return $result;
    }

    public function transition(User $actor, string $challengeId, string $action, array $input, string $key): array
    {
        $this->requiredKey($key);
        $challenge = LiveChallenge::where('public_id', $challengeId)->with('targets')->first();
        if (! $challenge) {
            throw $this->notFound();
        }
        $session = $this->scope->resolve($actor, GameSession::whereKey($challenge->game_session_id)->value('public_id'), true, true);
        $operator = $this->scope->operator($actor, $session);
        $target = $this->targetForActor($challenge, $actor, $session);
        if (! $operator && ! $target) {
            throw $this->notFound();
        }
        $fingerprint = $this->fingerprint($action, $challengeId, $input + ['actor' => $actor->public_id]);
        $idempotencyKey = 'live-challenge:'.hash('sha256', $key);
        $saved = $this->idempotency->reserve($idempotencyKey, $fingerprint);
        if ($saved['status'] === 'completed') {
            return $saved['response']['data'];
        }
        $result = DB::transaction(function () use ($actor, $challengeId, $action, $input, $session, $operator, $target): array {
            $challenge = LiveChallenge::where('public_id', $challengeId)->with('targets')->lockForUpdate()->firstOrFail();
            if ($action === 'cancel') {
                if (! $operator || trim((string) ($input['reason'] ?? '')) === '') {
                    throw new ApiException('AUTHORIZATION_DENIED', 'Assigned GameMaster access and a reason are required.', [], 403);
                }
                if (in_array($challenge->status, ['COMPLETED', 'EXPIRED', 'CANCELLED'], true)) {
                    throw new ApiException('STATE_TRANSITION_INVALID', 'The Challenge is terminal.', [], 409);
                }
                $challenge->forceFill(['status' => 'CANCELLED'])->save();
                $challenge->targets()->whereNotIn('status', ['COMPLETED', 'FAILED', 'EXPIRED'])->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);
                $this->event('live_challenge.cancelled.v1', $challenge, $actor, ['reason' => $input['reason']]);

                return $this->profile($challenge->fresh('targets'), true);
            }
            if (! $target) {
                throw $this->notFound();
            }
            if ($challenge->expires_at && $challenge->expires_at->isPast() && ! in_array($challenge->status, ['COMPLETED', 'CANCELLED', 'EXPIRED'], true)) {
                $challenge->forceFill(['status' => 'EXPIRED'])->save();
                $challenge->targets()->whereNotIn('status', ['COMPLETED', 'FAILED', 'CANCELLED'])->update(['status' => 'EXPIRED']);
                $this->event('live_challenge.expired.v1', $challenge, $actor, []);
                throw new ApiException('STATE_TRANSITION_INVALID', 'The Challenge has expired.', [], 409);
            }
            if ($action === 'ack') {
                if (! in_array($target->status, ['SENT', 'SEEN'], true)) {
                    throw new ApiException('STATE_TRANSITION_INVALID', 'The target cannot be acknowledged.', [], 409);
                }
                $target->forceFill(['status' => 'ACKED', 'seen_at' => $target->seen_at ?? now(), 'acked_at' => now()])->save();
                $this->event('live_challenge.target_acked.v1', $challenge, $actor, ['target_id' => $target->id]);
            } elseif ($action === 'start') {
                if ($challenge->status !== 'ACTIVE' || ! in_array($target->status, ['SENT', 'SEEN', 'ACKED'], true)) {
                    throw new ApiException('STATE_TRANSITION_INVALID', 'The Challenge is not startable.', [], 409);
                }
                $target->forceFill(['status' => 'STARTED', 'started_at' => now()])->save();
                $this->event('live_challenge.target_started.v1', $challenge, $actor, ['target_id' => $target->id]);
            } elseif ($action === 'submit') {
                if ($challenge->status !== 'ACTIVE' || ! in_array($target->status, ['ACKED', 'STARTED'], true) || ! ($input['acknowledged'] ?? false)) {
                    throw new ApiException('ACTION_NOT_ALLOWED', 'A valid acknowledgement is required.', [], 409);
                }
                $submission = new LiveChallengeSubmission;
                $submission->forceFill(['live_challenge_id' => $challenge->id, 'target_id' => $target->id, 'submitted_by' => $actor->id,
                    'evidence' => ['acknowledged' => true], 'result' => 'ACCEPTED', 'idempotency_key' => 'challenge:'.$challenge->public_id.':target:'.$target->id, 'occurred_at' => now()])->save();
                $target->forceFill(['status' => 'COMPLETED', 'completed_at' => now()])->save();
                $this->event('live_challenge.target_completed.v1', $challenge, $actor, ['target_id' => $target->id, 'submission_id' => $submission->submission_id]);
                if ($challenge->targets()->whereNotIn('status', ['COMPLETED'])->doesntExist()) {
                    $challenge->forceFill(['status' => 'COMPLETED'])->save();
                    $this->reward($challenge, $session, $target, $actor);
                    $this->event('live_challenge.completed.v1', $challenge, $actor, []);
                }
            }

            return $this->profile($challenge->fresh('targets'), $operator, $target);
        });
        $response = ['data' => $result];
        $this->idempotency->complete($idempotencyKey, $fingerprint, 200, $response);

        return $result;
    }

    public function message(User $actor, string $sessionId, array $input, string $key): array
    {
        $session = $this->operatorSession($actor, $sessionId);
        $this->requiredKey($key);
        if (! isset($input['body'], $input['target_type'], $input['target_id'])) {
            throw ValidationException::withMessages(['input' => 'A message body and target are required.']);
        }
        $fingerprint = $this->fingerprint('message', $sessionId, $input);
        $idempotencyKey = 'gamemaster-message:'.hash('sha256', $key);
        $saved = $this->idempotency->reserve($idempotencyKey, $fingerprint);
        if ($saved['status'] === 'completed') {
            return $saved['response']['data'];
        }
        $result = DB::transaction(function () use ($actor, $session, $input): array {
            $target = $this->resolveTargets($session, [['type' => $input['target_type'], 'id' => $input['target_id']]])[0];
            $message = new SessionMessage;
            $message->forceFill(['organization_id' => $session->organization_id, 'game_session_id' => $session->id, 'sent_by' => $actor->id,
                'target_type' => $target['type'], 'target_id' => $target['id'], 'priority' => $input['priority'] ?? 'NORMAL', 'body' => $input['body'], 'reason' => $input['reason'] ?? null, 'created_at' => now()])->save();
            $payload = ['message_id' => $message->message_id, 'session_id' => $session->public_id, 'target_type' => $target['type'], 'target_id' => $target['id'], 'priority' => $message->priority, 'body' => $message->body];
            $this->outbox->record($message->priority === 'EMERGENCY' ? 'gamemaster.emergency' : 'message.received', 'gamemaster', 'game_session', $session->public_id, $payload);
            $this->audit->record('gamemaster.message.sent', 'game_session', $session->public_id, $message->reason, ['message_id' => $message->message_id], $actor->id, $session->organization_id);

            return $payload;
        });
        $this->idempotency->complete($idempotencyKey, $fingerprint, 200, ['data' => $result]);

        return $result;
    }

    public function rebindDevice(User $actor, string $sessionId, string $playerId, array $input, string $key): array
    {
        $session = $this->operatorSession($actor, $sessionId);
        $player = $session->players()->where('public_id', $playerId)->with('user')->first();
        if (! $player) {
            throw $this->notFound();
        }
        $this->requiredKey($key);
        $result = $this->sessions->execute($actor, 'rebindDevice', ['device_id' => $input['device_id'], 'user_id' => $player->user->public_id, 'reason' => $input['reason']], $sessionId, null, $key);
        $this->audit->record('gamemaster.device_rebound', 'game_session_player', $player->public_id, $input['reason'], ['device_id' => $input['device_id']], $actor->id, $session->organization_id);

        return ['player_id' => $player->public_id, 'device_id' => $input['device_id'], 'result' => $result];
    }

    public function disqualify(User $actor, string $sessionId, string $playerId, array $input, string $key): array
    {
        $session = $this->operatorSession($actor, $sessionId);
        $this->requiredKey($key);
        $fingerprint = $this->fingerprint('disqualify', $playerId, $input);
        $idempotencyKey = 'gamemaster-disqualify:'.hash('sha256', $key);
        $saved = $this->idempotency->reserve($idempotencyKey, $fingerprint);
        if ($saved['status'] === 'completed') {
            return $saved['response']['data'];
        }
        $result = DB::transaction(function () use ($actor, $session, $playerId, $input): array {
            $player = $session->players()->where('public_id', $playerId)->lockForUpdate()->first();
            if (! $player || in_array($player->participation_status, ['REMOVED', 'FINISHED'], true)) {
                throw $this->notFound();
            }
            $player->forceFill(['participation_status' => 'DISQUALIFIED'])->save();
            foreach (TeamScenarioInstance::where('game_session_team_id', $player->game_session_team_id)->get() as $instance) {
                foreach (PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)->where('game_session_player_id', $player->id)->whereNull('released_at')->get() as $assignment) {
                    $this->roles->release($instance, $assignment, $actor);
                }
            }
            $this->outbox->record('player_session.disqualified.v1', 'gamemaster', 'game_session', $session->public_id, ['player_id' => $player->public_id, 'reason' => $input['reason']]);
            $this->audit->record('player_session.disqualified.v1', 'game_session_player', $player->public_id, $input['reason'], [], $actor->id, $session->organization_id);

            return ['player_id' => $player->public_id, 'status' => $player->participation_status];
        });
        $this->idempotency->complete($idempotencyKey, $fingerprint, 200, ['data' => $result]);

        return $result;
    }

    private function reward(LiveChallenge $challenge, GameSession $session, LiveChallengeTarget $target, User $actor): void
    {
        $delta = (int) ($challenge->reward_config['score_delta'] ?? 0);
        if ($delta === 0 || ! in_array($target->target_type, ['PLAYER', 'TEAM'], true)) {
            return;
        }
        $teamId = $target->target_type === 'TEAM'
            ? $session->teams()->whereHas('team', fn ($query) => $query->where('public_id', $target->target_id))->value('id')
            : GameSessionPlayer::where('public_id', $target->target_id)->value('game_session_team_id');
        $instance = TeamScenarioInstance::where('game_session_id', $session->id)->where('game_session_team_id', $teamId)->first();
        if (! $instance) {
            throw new ApiException('STATE_TRANSITION_INVALID', 'The Challenge reward has no active Team Runtime.', [], 409);
        }
        $player = $target->target_type === 'PLAYER' ? GameSessionPlayer::where('public_id', $target->target_id)->first() : null;
        $this->scoring->apply($instance, $player, null, $delta, 'LIVE_CHALLENGE_REWARD', 'Live Challenge reward', 'challenge-reward:'.$challenge->public_id, ['challenge_id' => $challenge->public_id], $actor->id, 'Live Challenge completion');
    }

    private function operatorSession(User $actor, string $sessionId): GameSession
    {
        $session = $this->scope->resolve($actor, $sessionId, true, true);
        if (! $this->scope->operator($actor, $session)) {
            throw new ApiException('AUTHORIZATION_DENIED', 'Assigned GameMaster access is required.', [], 403);
        }

        return $session;
    }

    private function targetForActor(LiveChallenge $challenge, User $actor, GameSession $session): ?LiveChallengeTarget
    {
        $player = $session->players()->where('user_id', $actor->id)->with('registration.team')->first();
        if (! $player) {
            return null;
        }

        return $challenge->targets->first(fn (LiveChallengeTarget $target): bool => ($target->target_type === 'SESSION' && $target->target_id === $session->public_id)
            || ($target->target_type === 'PLAYER' && $target->target_id === $player->public_id)
            || ($target->target_type === 'TEAM' && $target->target_id === $player->registration->team->public_id));
    }

    private function resolveTargets(GameSession $session, array $targets): array
    {
        $resolved = [];
        foreach ($targets as $target) {
            if (! is_array($target) || ! in_array($target['type'] ?? null, ['PLAYER', 'TEAM', 'SESSION'], true) || ! is_string($target['id'] ?? null) || ! Str::isUuid($target['id'])) {
                throw ValidationException::withMessages(['targets' => 'Every target must use a valid public UUID.']);
            }
            $valid = match ($target['type']) {
                'PLAYER' => $session->players()->where('public_id', $target['id'])->exists(),
                'TEAM' => $session->teams()->whereHas('team', fn ($query) => $query->where('public_id', $target['id']))->exists(),
                'SESSION' => $target['id'] === $session->public_id,
            };
            if (! $valid) {
                throw $this->notFound();
            }
            $resolved[] = ['type' => $target['type'], 'id' => $target['id']];
        }

        return $resolved;
    }

    private function validateCreate(array $input): void
    {
        $validator = validator($input, ['type' => 'required|string', 'targets' => 'required|array|min:1|max:8', 'targets.*.type' => 'required|string', 'targets.*.id' => 'required|uuid',
            'message' => 'sometimes|string|max:10000', 'objective' => 'sometimes|string|max:10000', 'duration_seconds' => 'sometimes|integer|min:1|max:86400',
            'expires_at' => 'sometimes|date', 'reward' => 'sometimes|array', 'penalty' => 'sometimes|array', 'interrupt_policy' => 'sometimes|in:PARALLEL,SOFT_INTERRUPT',
            'fairness_class' => 'sometimes|in:COSMETIC,SIDE_OBJECTIVE,COMPETITIVE_LOW_IMPACT,COMPETITIVE']);
        $validator->validate();
        $delta = data_get($input, 'reward.score_delta', 0);
        if (! is_int($delta) || abs($delta) > 1000) {
            throw ValidationException::withMessages(['reward.score_delta' => 'Pilot Challenge rewards are limited to 1000 points.']);
        }
    }

    private function profile(LiveChallenge $challenge, bool $operator, ?LiveChallengeTarget $target = null): array
    {
        if ($challenge->expires_at && $challenge->expires_at->isPast() && in_array($challenge->status, ['DRAFT', 'SCHEDULED', 'ACTIVE'], true)) {
            $challenge->forceFill(['status' => 'EXPIRED'])->save();
        }
        $data = ['id' => $challenge->public_id, 'type' => $challenge->type, 'status' => $challenge->status, 'priority' => $challenge->priority,
            'starts_at' => $challenge->starts_at?->toISOString(), 'expires_at' => $challenge->expires_at?->toISOString(), 'interrupt_policy' => $challenge->interrupt_policy,
            'fairness_class' => $challenge->fairness_class, 'config' => $challenge->config, 'targets' => $challenge->targets->map(fn (LiveChallengeTarget $item): array => [
                'type' => $item->target_type, 'id' => $operator || $target?->id === $item->id ? $item->target_id : null, 'status' => $item->status,
            ])->all()];
        if ($operator) {
            $data += ['reward_config' => $challenge->reward_config, 'penalty_config' => $challenge->penalty_config];
        }

        return $data;
    }

    private function event(string $type, LiveChallenge $challenge, User $actor, array $data): void
    {
        $payload = ['event_id' => (string) Str::uuid(), 'event_type' => $type, 'payload_version' => 1, 'occurred_at' => now()->toISOString(),
            'producer' => 'gamemaster', 'session_id' => GameSession::find($challenge->game_session_id)->public_id, 'challenge_id' => $challenge->public_id,
            'actor_id' => $actor->public_id, 'request_id' => request()->attributes->get('request_id'), 'data' => $data];
        $this->outbox->record($type, 'gamemaster', 'live_challenge', $challenge->public_id, $payload, 1, $payload['event_id']);
        $this->audit->record($type, 'live_challenge', $challenge->public_id, null, $data + ['event_id' => $payload['event_id']], $actor->id, $challenge->organization_id);
    }

    private function requiredKey(string $key): void
    {
        if (! is_string($key) || strlen($key) < 8) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'This operation requires an idempotency key.']);
        }
    }

    private function fingerprint(string $action, string $id, array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode([$action, $id, $input], JSON_THROW_ON_ERROR));
    }

    private function notFound(): ApiException
    {
        return new ApiException('RESOURCE_NOT_FOUND', 'The requested Live Challenge was not found.', [], 404);
    }
}

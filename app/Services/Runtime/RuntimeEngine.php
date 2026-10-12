<?php

namespace App\Services\Runtime;

use App\Exceptions\ApiException;
use App\Models\GameSession;
use App\Models\GameSessionPlayer;
use App\Models\PlayerRoleAssignment;
use App\Models\RuntimeCommand;
use App\Models\RuntimeTimer;
use App\Models\ScenarioEdge;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Audit\AuditService;
use App\Services\Games\GameEvents;
use App\Services\Games\GameScope;
use App\Services\Scenarios\AuthoringNodeCatalog;
use App\Services\Scoring\ScoringService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RuntimeEngine
{
    public function __construct(private GameScope $scope, private RuntimeEvents $events, private RuntimeHandlers $handlers,
        private RuntimeProjection $projection, private RuntimeConditions $conditions, private RuntimeTimers $timers, private RuntimeRoles $roles,
        private RuntimeSubgroups $subgroups, private ScoringService $scoring) {}

    public function read(User $actor, string $sessionId, ?string $teamId = null, bool $self = false, ?UserDevice $device = null): array
    {
        return DB::transaction(function () use ($actor, $sessionId, $teamId, $self, $device): array {
            $session = $this->scope->resolve($actor, $sessionId, true, true);
            $player = $session->players()->where('user_id', $actor->id)->whereNotIn('participation_status', ['REMOVED', 'DISQUALIFIED'])->first();
            $query = TeamScenarioInstance::where('game_session_id', $session->id);
            if ($self || ! $this->scope->operator($actor, $session)) {
                if (! $player) {
                    throw $this->notFound();
                }
                $query->where('game_session_team_id', $player->game_session_team_id);
            }
            if ($teamId !== null) {
                if (! Str::isUuid($teamId)) {
                    throw $this->notFound();
                }
                $query->whereHas('registration.team', fn ($q) => $q->where('public_id', $teamId));
            }
            if ($self) {
                $this->device($actor, $player, $device, false);
            }
            $instances = $query->get();
            if ($instances->isEmpty()) {
                throw $this->notFound();
            }
            $result = $instances->map(fn (TeamScenarioInstance $i): array => $this->projection->instance($i, $player?->game_session_team_id === $i->game_session_team_id ? $player : null))->all();

            return $self || $teamId !== null ? $result[0] : ['instances' => $result];
        });
    }

    /** Session lock serializes pause, roster/device changes, progression and all team commands. */
    public function execute(User $actor, string $type, string $target, array $input, string $key, ?UserDevice $device = null): array
    {
        Validator::make(['key' => $key], ['key' => 'required|string|min:8|max:255'])->validate();
        $key = 'client:'.hash('sha256', $key);
        $nodeCommands = ['start_node', 'submit_action', 'complete_node', 'skip_node', 'assign_role', 'release_role'];
        $teamCommands = ['pause_team', 'resume_team', 'reconnect_player', 'disconnect_player'];
        if (! in_array($type, [...$nodeCommands, ...$teamCommands], true) || ! Str::isUuid($target)) {
            throw $this->notFound();
        }
        $outcome = DB::transaction(function () use ($actor, $type, $target, $input, $key, $device, $nodeCommands): array {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $node = in_array($type, $nodeCommands, true) ? TeamNodeInstance::where('public_id', $target)->first() : null;
            $instance = $node ? $node->instance : TeamScenarioInstance::where('public_id', $target)->first();
            if (! $instance || (in_array($type, $nodeCommands, true) && ! $node)) {
                throw $this->notFound();
            }
            $session = $this->scope->resolve($actor, $instance->session->public_id, true, true);
            $instance = TeamScenarioInstance::whereKey($instance->id)->lockForUpdate()->sole();
            $node = $node ? TeamNodeInstance::whereKey($node->id)->lockForUpdate()->sole() : null;
            $player = $session->players()->where('user_id', $actor->id)->where('game_session_team_id', $instance->game_session_team_id)->first();
            $operational = in_array($type, ['skip_node', 'assign_role', 'release_role', 'pause_team', 'resume_team'], true);
            if ($operational) {
                if (! $this->scope->operator($actor, $session)) {
                    throw $this->notFound();
                }
            } elseif (! $player || ! in_array($player->participation_status, ['ACTIVE', 'DISCONNECTED', 'FINISHED'], true)) {
                throw $this->notFound();
            } else {
                $this->device($actor, $player, $device, true);
            }
            $request = $this->canonical(['actor' => $actor->public_id, 'device' => $device?->public_id, 'type' => $type, 'target' => $target, 'input' => $input]);
            $hash = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
            DB::table('runtime_commands')->insertOrIgnore(['command_id' => (string) Str::uuid(), 'game_session_id' => $session->id,
                'team_scenario_instance_id' => $instance->id, 'player_id' => $player?->id, 'actor_id' => $actor->id,
                'command_type' => $type, 'idempotency_key' => $key, 'request_hash' => $hash,
                'request_payload' => json_encode($request, JSON_THROW_ON_ERROR), 'status' => 'RECEIVED', 'created_at' => now()]);
            $command = RuntimeCommand::where('idempotency_key', $key)->lockForUpdate()->sole();
            if ($command->request_hash !== $hash) {
                throw new ApiException('IDEMPOTENCY_CONFLICT', 'The key belongs to a different request.', [], 409);
            }
            if (in_array($command->status, ['SUCCEEDED', 'REJECTED', 'FAILED'], true)) {
                return $command->result_payload;
            }
            $command->forceFill(['status' => 'PROCESSING'])->save();
            try {
                $result = DB::transaction(function () use ($actor, $type, $input, $instance, $session, $node, $player, $command, $operational, $device): array {
                    $this->validate($type, $input);
                    if (! in_array($session->status, ['RUNNING', 'PAUSED'], true)) {
                        throw new ApiException('STATE_TRANSITION_INVALID', 'Session gameplay is closed.', [], 409);
                    }
                    if ($operational && trim($input['reason'] ?? '') === '') {
                        throw new ApiException('ACTION_NOT_ALLOWED', 'A reason is required.', [], 422);
                    }
                    if (in_array($type, ['reconnect_player', 'disconnect_player'], true)) {
                        if (! in_array($instance->status, ['ACTIVE', 'PAUSED'], true)) {
                            throw new ApiException('STATE_TRANSITION_INVALID', 'Team gameplay is closed.', [], 409);
                        }
                        if ($player->participation_status !== ($type === 'reconnect_player' ? 'ACTIVE' : 'DISCONNECTED')) {
                            $player->forceFill(['participation_status' => $type === 'reconnect_player' ? 'ACTIVE' : 'DISCONNECTED'])->save();
                            app(GameEvents::class)->record($type === 'reconnect_player' ? 'player_session.reconnected.v1' : 'player_session.disconnected.v1', $actor, $session, ['player_id' => $player->public_id, 'team_id' => $instance->registration->team->public_id]);
                        }

                        return ['accepted' => true, 'runtime' => $this->projection->instance($instance, $player)];
                    }
                    if (in_array($type, ['pause_team', 'resume_team'], true)) {
                        $this->pause($instance, 'team', $type === 'pause_team', $actor, $input['reason']);

                        return ['accepted' => true, 'runtime' => $this->projection->instance($instance, $player)];
                    }
                    if ($session->status !== 'RUNNING' || $instance->status !== 'ACTIVE') {
                        throw new ApiException('STATE_TRANSITION_INVALID', 'Gameplay is paused or terminal.', [], 409);
                    }
                    if (! $operational) {
                        $this->roles->eligible($instance, $node, $player, $device);
                    }
                    $result = ['accepted' => true];
                    if ($type === 'start_node') {
                        if ($node->status !== 'AVAILABLE') {
                            throw new ApiException('STATE_TRANSITION_INVALID', 'Node must be AVAILABLE.', [], 409);
                        }
                        $this->start($instance, $node, $actor, $player);
                    } elseif ($type === 'skip_node') {
                        if (! in_array($node->status, ['AVAILABLE', 'ACTIVE'], true) || $node->node->node_type === 'Finish') {
                            throw new ApiException('STATE_TRANSITION_INVALID', 'Node cannot be skipped.', [], 409);
                        }
                        $this->terminal($instance, $node, 'SKIPPED', $actor, null, $input['reason']);
                    } elseif ($type === 'assign_role') {
                        if (! in_array($node->status, ['AVAILABLE', 'ACTIVE'], true)) {
                            throw new ApiException('STATE_TRANSITION_INVALID', 'Role context is closed.', [], 409);
                        }
                        $targetPlayer = $instance->registration->players()->where('public_id', $input['player_id'])->first();
                        if (! $targetPlayer) {
                            throw $this->notFound();
                        }
                        $this->roles->assign($instance, $node, $targetPlayer, $input['role_code'], $input['slot_no'], 'GAMEMASTER', $actor);
                    } elseif ($type === 'release_role') {
                        $assignment = PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)->where('public_id', $input['assignment_id'])->first();
                        if (! $assignment) {
                            throw $this->notFound();
                        }
                        $this->roles->release($instance, $assignment, $actor);
                    } else {
                        if ($node->status !== 'ACTIVE') {
                            throw new ApiException('STATE_TRANSITION_INVALID', 'Node must be ACTIVE.', [], 409);
                        }
                        $config = $node->node->config;
                        if (isset($config['timeout_seconds']) || ($node->node->node_type === 'Time' && in_array($config['mode'], ['TIMEOUT', 'TIME_WINDOW'], true))) {
                            $timer = RuntimeTimer::where('node_instance_id', $node->id)->first();
                            if ($timer && $this->timers->elapsed($timer) >= $timer->duration_seconds) {
                                throw new ApiException('STATE_TRANSITION_INVALID', 'The server deadline has passed.', [], 409);
                            }
                        }
                        if (isset($node->state_json['retry_at']) && now()->timestamp < $node->state_json['retry_at']) {
                            throw new ApiException('ACTION_NOT_ALLOWED', 'Wait before retrying this node.', [], 409);
                        }
                        $result = $this->handlers->submit($node, $player, $input, $command->command_id);
                        $node->forceFill(['attempt_count' => $node->attempt_count + 1, 'version_no' => $node->version_no + 1])->save();
                        $this->events->record('node_submission_received', $instance, $node, $actor, $player, [
                            'command_id' => $command->command_id, 'result' => $result['result'] ?? ($result['accepted'] ? 'ACCEPTED' : 'REJECTED'),
                            'contribution' => $result['contribution'] ?? false, 'progress' => $result['progress'] ?? null,
                            'role_code' => $result['role_code'] ?? $this->roles->roleFor($instance, $player)]);
                        if (! $result['accepted']) {
                            $this->events->record('node_submission_rejected', $instance, $node, $actor, $player, ['command_id' => $command->command_id]);
                            $config = $node->node->config;
                            if (($result['result'] ?? '') !== 'WAITING') {
                                $node->forceFill(['state_json' => array_replace($node->state_json ?? [], ['retry_at' => now()->timestamp + ($config['retry_delay'] ?? 0)])])->save();
                                if (($config['allow_retry'] ?? true) === false || (isset($config['max_attempts']) && $node->attempt_count >= $config['max_attempts']
                                    && ScenarioEdge::where('from_node_id', $node->scenario_node_id)->where('metadata->outcome', 'FAILURE')->exists())) {
                                    $result['fail'] = true;
                                    app(AuthoringRuntime::class)->outcome($node, 'FAILURE');
                                }
                            }
                        }
                        if ($result['complete'] ?? false) {
                            $this->terminal($instance, $node, 'COMPLETED', $actor, $player);
                        } elseif ($result['fail'] ?? false) {
                            $this->terminal($instance, $node, 'FAILED', $actor, $player);
                        }
                    }
                    if ($operational) {
                        app(AuditService::class)->record('runtime.command.'.$type, 'team_scenario_instance', $instance->public_id, $input['reason'], ['command_id' => $command->command_id], $actor->id, $session->organization_id);
                    }

                    return $result + ['node' => $this->projection->node($node->fresh(), player: $player), 'runtime' => $this->projection->instance($instance->fresh(), $player)];
                });
                $outcome = ['result' => $result];
                $status = ($result['accepted'] ?? true) ? 'SUCCEEDED' : 'REJECTED';
            } catch (ApiException $e) {
                $outcome = ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'status' => $e->status]];
                $status = 'REJECTED';
            } catch (ValidationException $e) {
                $outcome = ['error' => ['code' => 'VALIDATION_FAILED', 'message' => 'The runtime request is invalid.', 'status' => 422]];
                $status = 'REJECTED';
            } catch (\Throwable $e) {
                report($e);
                $outcome = ['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'The runtime command failed.', 'status' => 500]];
                $status = 'FAILED';
            }
            $command->forceFill(['status' => $status, 'result_payload' => $outcome, 'completed_at' => now()])->save();

            return $outcome;
        });
        if (isset($outcome['error'])) {
            throw new ApiException($outcome['error']['code'], $outcome['error']['message'], [], $outcome['error']['status']);
        }

        return $this->canonical($outcome['result']);
    }

    public function start(TeamScenarioInstance $instance, TeamNodeInstance $node, ?User $actor = null, ?GameSessionPlayer $player = null): void
    {
        $node->forceFill(['status' => 'ACTIVE', 'started_at' => now(), 'version_no' => $node->version_no + 1])->save();
        $this->events->record('node_started', $instance, $node, $actor, $player);
        if (in_array($node->node->node_type, ['Timer', 'Wait', 'Time'], true) || isset($node->node->config['timeout_seconds'])) {
            $this->timers->start($instance, $node);
        }
    }

    public function unlock(TeamScenarioInstance $instance, TeamNodeInstance $node, ?GameSessionPlayer $player = null): void
    {
        if ($node->status !== 'LOCKED') {
            return;
        }
        $node->forceFill(['status' => 'AVAILABLE', 'version_no' => $node->version_no + 1])->save();
        $this->events->record('node_unlocked', $instance, $node);
        $type = $node->node->node_type;
        if (in_array($type, AuthoringNodeCatalog::AUTOMATIC, true)
            || ($type === 'Condition' && isset($node->node->config['operand']))
            || ($type === 'Time' && ($node->node->config['mode'] ?? '') === 'ELAPSED_CHECK')) {
            $this->start($instance, $node);
            app(AuthoringRuntime::class)->automatic($node, $player);
            $this->terminal($instance, $node, 'COMPLETED', null, $player);

            return;
        }
        if ($type === 'Time') {
            $this->start($instance, $node);
        }
        if ($type === 'Random Player') {
            $players = $instance->registration->players()->whereIn('participation_status', ['READY', 'ACTIVE', 'DISCONNECTED'])->orderBy('public_id')->get();
            if ($players->isEmpty()) {
                throw new ApiException('ACTION_NOT_ALLOWED', 'No eligible random Player.', [], 409);
            }
            $index = (int) floor($this->conditions->random($instance, 'player:'.$node->node->node_key) * $players->count());
            $node->forceFill(['state_json' => array_replace($node->state_json ?? [], ['selected_player_id' => $players[$index]->public_id])])->save();
        }
        if (in_array($type, ['Start', 'Branch', 'Condition', 'Assign Roles', 'Swap Roles', 'Split Team'], true)) {
            $this->start($instance, $node);
            if (in_array($type, ['Assign Roles', 'Swap Roles'], true)) {
                $this->roles->rotate($instance, $node);
            }
            $this->terminal($instance, $node, 'COMPLETED', null, $player);
        }
    }

    public function terminal(TeamScenarioInstance $instance, TeamNodeInstance $node, string $status, ?User $actor = null, ?GameSessionPlayer $player = null, ?string $reason = null): void
    {
        if (! in_array($status, ['COMPLETED', 'FAILED', 'SKIPPED'], true)
            || ! in_array($node->status, $status === 'SKIPPED' ? ['AVAILABLE', 'ACTIVE'] : ['ACTIVE'], true)) {
            throw new ApiException('STATE_TRANSITION_INVALID', 'Invalid node terminal transition.', [], 409);
        }
        if ($status === 'FAILED' && ($node->node->node_type !== 'Timer' || ($node->node->config['on_expire'] ?? null) !== 'FAIL')
            && ! isset($node->node->config['timeout_seconds']) && ! isset($node->node->config['max_attempts'])
            && ($node->node->config['allow_retry'] ?? true) !== false && $node->node->node_type !== 'Time'
            && ! in_array($node->node->node_type, ['PhotoSubmission', 'AudioSubmission', 'FileSubmission'], true)) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'This node does not support failure.', [], 409);
        }
        if ($node->node->node_type === 'Finish' && TeamNodeInstance::where('team_scenario_instance_id', $instance->id)->whereKeyNot($node->id)->whereIn('status', ['AVAILABLE', 'ACTIVE'])->exists()) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Other required active flow is unresolved.', [], 409);
        }
        $node->forceFill(['status' => $status, 'completed_at' => now(), 'version_no' => $node->version_no + 1])->save();
        $this->timers->finish($instance, $node);
        $targets = $this->next($instance, $node, $player);
        if ($node->node->node_type === 'Split Team') {
            $this->subgroups->assign($instance, $node, $targets);
        }
        $action = ['COMPLETED' => 'node_completed', 'FAILED' => 'node_failed', 'SKIPPED' => 'node_skipped'][$status];
        $this->events->record($action, $instance, $node, $actor, $player, ['completed_by_player_id' => $player?->public_id,
            'completion_metadata' => ['status' => $status], 'next_node_ids' => $targets->pluck('public_id')->all()], $reason);
        if ($status === 'COMPLETED') {
            $this->scoring->recordCompletion($instance, $node, $player);
        }
        foreach ($targets as $target) {
            $this->unlock($instance, $target->fresh(), $player);
        }
        if ($node->node->node_type === 'Finish' && $status === 'COMPLETED') {
            $instance->forceFill(['status' => 'COMPLETED', 'completed_at' => now()])->save();
            $this->timers->finish($instance);
            $this->scoring->finalize($instance->fresh());
            $this->events->record('completed', $instance, actor: $actor, player: $player);
            $instance->registration->forceFill(['status' => 'FINISHED'])->save();
            foreach ($instance->registration->players()->whereIn('participation_status', ['ACTIVE', 'DISCONNECTED', 'JOINED', 'READY'])->get() as $participant) {
                $participant->forceFill(['participation_status' => 'FINISHED'])->save();
                if ($actor) {
                    app(GameEvents::class)->record('player_session.finished.v1', $actor, $instance->session, ['player_id' => $participant->public_id, 'team_id' => $instance->registration->team->public_id]);
                }
            }
            if ($actor) {
                app(GameEvents::class)->record('session_team.finished.v1', $actor, $instance->session, ['team_id' => $instance->registration->team->public_id]);
            }
            $player?->refresh();
        }
    }

    private function next(TeamScenarioInstance $instance, TeamNodeInstance $node, ?GameSessionPlayer $player): Collection
    {
        $edges = ScenarioEdge::where('scenario_version_id', $instance->scenario_version_id)->where('from_node_id', $node->scenario_node_id)->orderBy('priority')->orderBy('edge_key')->get();
        if ($edges->contains(fn (ScenarioEdge $edge): bool => isset($edge->metadata['outcome']))) {
            $outcome = $node->state_json['outcome'] ?? ($node->status === 'FAILED' ? 'FAILURE' : 'SUCCESS');
            $matched = $edges->filter(fn (ScenarioEdge $edge): bool => ($edge->metadata['outcome'] ?? null) === $outcome);

            return TeamNodeInstance::where('team_scenario_instance_id', $instance->id)->whereIn('scenario_node_id', $matched->pluck('to_node_id'))->get();
        }
        $conditionPassed = $node->node->node_type !== 'Condition'
            || $this->conditions->evaluate($instance, $node->node->config['condition_group_id'], $node, $player);

        $matched = $edges->filter(fn (ScenarioEdge $edge): bool => $conditionPassed && $edge->condition_group_id !== null && $this->conditions->evaluate($instance, $edge->condition_group_id, $node, $player));
        if ($matched->isEmpty()) {
            $matched = $edges->whereNull('condition_group_id');
        }
        if ($node->node->node_type !== 'Split Team') {
            $matched = $matched->take(1);
        }

        return TeamNodeInstance::where('team_scenario_instance_id', $instance->id)->whereIn('scenario_node_id', $matched->pluck('to_node_id'))->orderBy('id')->get();
    }

    public function pause(TeamScenarioInstance $instance, string $source, bool $pause, ?User $actor = null, ?string $reason = null): void
    {
        if (! in_array($instance->status, ['ACTIVE', 'PAUSED'], true)) {
            throw new ApiException('STATE_TRANSITION_INVALID', 'Runtime cannot be paused or resumed.', [], 409);
        }
        $sources = $instance->pause_sources ?? [];
        $sources = $pause ? array_values(array_unique([...$sources, $source])) : array_values(array_diff($sources, [$source]));
        $status = $sources === [] ? 'ACTIVE' : 'PAUSED';
        $changed = $status !== $instance->status;
        $instance->forceFill(['pause_sources' => $sources, 'status' => $status])->save();
        if ($changed) {
            $this->timers->pause($instance, $status === 'PAUSED', $actor);
            $this->events->record($status === 'PAUSED' ? 'paused' : 'resumed', $instance, actor: $actor, reason: $reason);
        }
    }

    /** Called inside the existing Session transition transaction after its lock is acquired. */
    public function sessionTransition(GameSession $session, string $to, User $actor, string $transitionId, ?string $reason): void
    {
        foreach (TeamScenarioInstance::where('game_session_id', $session->id)->whereIn('status', ['ACTIVE', 'PAUSED'])->orderBy('id')->lockForUpdate()->get() as $instance) {
            $key = 'session:'.$transitionId.':'.$instance->public_id;
            if (RuntimeCommand::where('idempotency_key', $key)->exists()) {
                continue;
            }
            if (in_array($to, ['PAUSED', 'RUNNING'], true)) {
                $this->pause($instance, 'session', $to === 'PAUSED', $actor, $reason);
            } elseif (in_array($to, ['FINISHED', 'CANCELLED'], true)) {
                $instance->forceFill(['status' => 'CANCELLED', 'completed_at' => now()])->save();
                $this->timers->finish($instance, cancel: true);
                $this->events->record('cancelled', $instance, actor: $actor, data: ['session_status' => $to], reason: $reason);
            }
            (new RuntimeCommand)->forceFill(['command_id' => (string) Str::uuid(), 'game_session_id' => $session->id,
                'team_scenario_instance_id' => $instance->id, 'actor_id' => $actor->id, 'command_type' => 'session_transition',
                'idempotency_key' => $key, 'request_hash' => hash('sha256', $key), 'request_payload' => ['session_status' => $to],
                'result_payload' => ['status' => $instance->status], 'status' => 'SUCCEEDED', 'created_at' => now(), 'completed_at' => now()])->save();
        }
    }

    private function device(User $actor, GameSessionPlayer $player, ?UserDevice $device, bool $lock): void
    {
        $query = UserDevice::whereKey($device?->id)->where('user_id', $actor->id)->whereNull('revoked_at');
        if ($lock) {
            $query->lockForUpdate();
        }
        $bound = $query->first();
        if (! $bound || $player->active_device_id !== $bound->id || $player->roster_frozen_at === null) {
            throw new ApiException('DEVICE_NOT_BOUND', 'Use the active Device bound to the frozen roster.', [], 409);
        }
    }

    /** Durable scheduler entry; retries recover exclusively from PostgreSQL. */
    public function expireTimers(): int
    {
        $count = 0;
        $ids = RuntimeTimer::where('status', 'RUNNING')->whereNotNull('node_instance_id')->whereNotNull('duration_seconds')->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id): int {
                $timer = RuntimeTimer::find($id);
                if (! $timer) {
                    return 0;
                }
                $instance = TeamScenarioInstance::findOrFail($timer->team_scenario_instance_id);
                $session = GameSession::whereKey($instance->game_session_id)->lockForUpdate()->sole();
                $instance = TeamScenarioInstance::whereKey($instance->id)->lockForUpdate()->sole();
                $node = TeamNodeInstance::whereKey($timer->node_instance_id)->lockForUpdate()->sole();
                $timer = RuntimeTimer::whereKey($id)->lockForUpdate()->sole();
                if ($session->status !== 'RUNNING' || $instance->status !== 'ACTIVE' || $node->status !== 'ACTIVE'
                    || $timer->status !== 'RUNNING' || $this->timers->elapsed($timer) < $timer->duration_seconds) {
                    return 0;
                }
                $prefix = 'timer-expiry:'.$timer->public_id.':';
                $attempts = RuntimeCommand::where('idempotency_key', 'like', $prefix.'%')->orderByDesc('id')->get();
                if ($attempts->contains('status', 'SUCCEEDED') || $attempts->count() >= (int) config('runtime.timer_max_attempts', 3)) {
                    return 0;
                }
                if ($attempts->isNotEmpty() && now()->timestamp < $attempts->first()->created_at->timestamp + (int) config('runtime.timer_retry_seconds', 60) * (2 ** ($attempts->count() - 1))) {
                    return 0;
                }
                $key = $prefix.($attempts->count() + 1);
                $command = new RuntimeCommand;
                $command->forceFill(['command_id' => (string) Str::uuid(), 'game_session_id' => $session->id, 'team_scenario_instance_id' => $instance->id,
                    'command_type' => 'timer_expiry', 'idempotency_key' => $key, 'request_hash' => hash('sha256', $key),
                    'request_payload' => ['timer_id' => $timer->public_id], 'status' => 'PROCESSING', 'created_at' => now()])->save();
                try {
                    DB::transaction(function () use ($instance, $node): void {
                        $config = $node->node->config;
                        $timeout = isset($config['timeout_seconds']) || ($node->node->node_type === 'Time' && in_array($config['mode'], ['TIMEOUT', 'TIME_WINDOW'], true));
                        if ($timeout) {
                            app(AuthoringRuntime::class)->outcome($node, 'TIMEOUT');
                        }
                        $this->terminal($instance, $node, $timeout || ($config['on_expire'] ?? 'COMPLETE') === 'FAIL' ? 'FAILED' : 'COMPLETED');
                    });
                    $command->forceFill(['status' => 'SUCCEEDED', 'result_payload' => ['node_status' => $node->status], 'completed_at' => now()])->save();

                    return 1;
                } catch (\Throwable $e) {
                    report($e);
                    $command->forceFill(['status' => $e instanceof ApiException ? 'REJECTED' : 'FAILED',
                        'result_payload' => ['error_code' => $e instanceof ApiException ? $e->errorCode : 'INTERNAL_ERROR',
                            'attempt' => $attempts->count() + 1, 'retry_exhausted' => $attempts->count() + 1 >= (int) config('runtime.timer_max_attempts', 3)],
                        'completed_at' => now()])->save();

                    return 0;
                }
            });
        }

        return $count;
    }

    private function validate(string $type, array $input): void
    {
        $rules = match ($type) {
            'start_node','reconnect_player','disconnect_player' => [],
            'submit_action','complete_node' => ['answer' => 'sometimes|string|max:1000', 'acknowledged' => 'sometimes|boolean',
                'selected_option_refs' => 'sometimes|array|list|max:20', 'selected_option_refs.*' => 'string|distinct|max:1000', 'evidence_ref' => 'sometimes|string|max:2048', 'qr_token' => 'sometimes|string|max:512'],
            'assign_role' => ['reason' => 'required|string|max:1000', 'player_id' => 'required|uuid', 'role_code' => 'required|in:SCANNER,NAVIGATOR,DECODER,AR_OPERATOR', 'slot_no' => 'required|integer|min:1|max:4'],
            'release_role' => ['reason' => 'required|string|max:1000', 'assignment_id' => 'required|uuid'],
            default => ['reason' => 'required|string|max:1000'],
        };
        if (array_diff(array_keys($input), array_keys($rules))) {
            throw ValidationException::withMessages(['input' => 'Unknown runtime input.']);
        }
        Validator::make($input, $rules)->validate();
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }

    private function notFound(): ApiException
    {
        return new ApiException('RESOURCE_NOT_FOUND', 'Runtime not found.', [], 404);
    }
}

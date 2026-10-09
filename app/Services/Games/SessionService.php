<?php

namespace App\Services\Games;

use App\Exceptions\ApiException;
use App\Models\GameSession;
use App\Models\GameSessionPlayer;
use App\Models\GameSessionRosterSnapshot;
use App\Models\GameSessionStateTransition;
use App\Models\SessionReadyEvent;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Audit\AuditService;
use App\Services\Idempotency\IdempotencyStore;
use App\Services\Organizations\OrganizationScope;
use App\Services\Runtime\RuntimeEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SessionService
{
    public function __construct(private GameScope $scope, private OrganizationScope $organizations, private GameEvents $events, private SessionRoster $roster, private SessionDependencies $dependencies, private IdempotencyStore $idempotency, private AuditService $audit) {}

    /** Actor -> Organization -> Game -> Session -> Team -> roster accounts/devices. All domain writes share this boundary. */
    public function execute(User $actor, string $action, array $input = [], ?string $id = null, ?string $target = null, ?string $key = null, ?UserDevice $requestDevice = null): array
    {
        if (! in_array($action, ['create', 'update', 'openRegistration', 'closeRegistration', 'registerTeam', 'removeTeam', 'bindDevice', 'rebindDevice', 'ready', 'start', 'pause', 'resume', 'finish', 'cancel', 'syncRoster', 'lateJoin'], true)) {
            throw new \InvalidArgumentException('Unknown Session operation.');
        }
        $authorizedStart = null;
        try {
            return DB::transaction(function () use ($actor, $action, $input, $id, $target, $key, $requestDevice, &$authorizedStart): array {
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $actor->ensureActive();
                if ($action === 'syncRoster') {
                    Validator::make($input, ['user_ids' => 'required|array|min:1|max:8', 'user_ids.*' => 'required|uuid|distinct', 'reason' => 'sometimes|nullable|string|max:1000'])->validate();
                    if (array_diff(array_keys($input), ['user_ids', 'reason'])) {
                        throw ValidationException::withMessages(['input' => 'Unknown roster field.']);
                    }
                } else {
                    $input = GameInput::validate($input, match ($action) {
                        'create' => 'sessionCreate','update' => 'sessionUpdate',default => $action
                    });
                }
                if ($action === 'create') {
                    $game = $this->scope->resolve($actor, $input['game_id'], false, true);
                    Gate::forUser($actor)->authorize('create', [GameSession::class, $game]);
                    $session = new GameSession;
                    $session->forceFill(['game_id' => $game->id, 'organization_id' => $game->organization_id, 'created_by' => $actor->id, 'timezone' => $input['timezone'] ?? $game->default_timezone, 'status' => 'DRAFT']);
                    $session->setRelation('game', $game);
                    $session->setRelation('organization', $game->organization);
                } else {
                    $session = $this->scope->resolve($actor, $id ?? '', true, true);
                    if (! in_array($action, ['registerTeam', 'removeTeam', 'bindDevice', 'rebindDevice', 'ready', 'syncRoster', 'lateJoin'], true)) {
                        Gate::forUser($actor)->authorize($action === 'cancel' && $session->started_at !== null ? 'emergency' : 'operate', [$session, $action]);
                    }
                }
                if ($this->organizations->isSuperAdmin($actor)) {
                    GameInput::reason($input);
                }
                if ($action === 'start') {
                    $authorizedStart = $session;
                }
                if ($session->organization->status !== 'ACTIVE') {
                    throw new ApiException('ACTION_NOT_ALLOWED', 'Organization is archived.', [], 409);
                }
                if (in_array($action, ['create', 'openRegistration', 'closeRegistration', 'registerTeam', 'start'], true) && $session->game->status !== 'ACTIVE') {
                    throw new ApiException('STATE_TRANSITION_INVALID', 'An active Game is required.', [], 409);
                }
                if (in_array($action, ['start', 'rebindDevice', 'pause', 'resume', 'finish', 'cancel'], true) && $key === null) {
                    throw ValidationException::withMessages(['Idempotency-Key' => 'This operation requires an idempotency key.']);
                }
                $fingerprint = '';
                if ($key !== null) {
                    Validator::make(['key' => $key], ['key' => 'required|string|min:8|max:255'])->validate();
                    $sorted = $input;
                    ksort($sorted);
                    $fingerprint = hash('sha256', json_encode([$action, $id, $target, $sorted, $requestDevice?->public_id], JSON_THROW_ON_ERROR));
                    $key = 'sessions:'.$actor->public_id.':'.hash('sha256', $key);
                    $saved = $this->idempotency->reserve($key, $fingerprint);
                    if ($saved['status'] === 'completed') {
                        if (in_array($action, ['registerTeam', 'removeTeam', 'syncRoster'], true)) {
                            $this->roster->team($actor, $session, $target ?? ($input['team_id'] ?? ''), $input);
                        }
                        if (in_array($action, ['rebindDevice', 'bindDevice'], true) && isset($input['user_id']) && $input['user_id'] !== $actor->public_id) {
                            Gate::forUser($actor)->authorize('operate', [$session, $action]);
                        }

                        return $saved['response']['data'];
                    }
                    if ($saved['status'] !== 'reserved') {
                        throw new ApiException('CONFLICT', 'Operation is pending.', [], 409);
                    }
                }
                $extra = [];
                switch ($action) {
                    case 'create':
                        $this->configuration($session, $input);
                        $session->save();
                        $this->events->record('game_session.created.v1', $actor, $session);
                        break;
                    case 'update':
                        $this->state($session, ['DRAFT', 'REGISTRATION', 'READY']);
                        $this->configuration($session, $input);
                        $session->save();
                        $this->audit->record('game_session.update', 'game_session', $session->public_id, $input['reason'] ?? null, ['fields' => array_keys($input)], $actor->id, $session->organization_id);
                        break;
                    case 'openRegistration':
                        $this->state($session, ['DRAFT']);
                        if (! $this->dependencies->pricing->registrationReady($session)) {
                            throw new ApiException('SESSION_BILLING_NOT_READY', 'Registration pricing is not ready.', [], 409);
                        }
                        $this->transition($actor, $session, 'REGISTRATION', 'registration_opened', $input);
                        break;
                    case 'closeRegistration':
                        $this->state($session, ['REGISTRATION']);
                        $context = $this->roster->context($session);
                        $this->dependencies->billingPass($session, $context, 'prepare');
                        $this->dependencies->assignments($session, $context);
                        $this->transition($actor, $session, 'READY', 'registration_closed', $input);
                        break;
                    case 'registerTeam':
                        $this->roster->register($actor, $session, $input);
                        break;
                    case 'removeTeam':
                        $this->roster->remove($actor, $session, $target ?? '', $input);
                        break;
                    case 'syncRoster':
                        $this->syncRoster($actor, $session, $target ?? '', $input);
                        break;
                    case 'bindDevice':
                    case 'rebindDevice':
                        $this->bind($actor, $session, $input, $action === 'rebindDevice');
                        break;
                    case 'ready':
                    case 'lateJoin':
                        $this->ready($actor, $session, $input, $requestDevice, $action === 'lateJoin');
                        break;
                    case 'start':
                        $extra = $this->start($actor, $session, $input);
                        break;
                    case 'pause':
                        $this->state($session, ['RUNNING']);
                        $this->transition($actor, $session, 'PAUSED', 'paused', $input);
                        break;
                    case 'resume':
                        $this->state($session, ['PAUSED']);
                        $this->transition($actor, $session, 'RUNNING', 'resumed', $input);
                        break;
                    case 'finish':
                        $this->state($session, ['RUNNING', 'PAUSED']);
                        $session->finished_at = now();
                        foreach ($session->teams()->where('status', 'ACTIVE')->get() as $team) {
                            $team->forceFill(['status' => 'FINISHED'])->save();
                            $this->events->record('session_team.finished.v1', $actor, $session, ['team_id' => $team->team->public_id]);
                        }
                        foreach ($session->players()->whereNotIn('participation_status', ['REMOVED', 'DISQUALIFIED', 'FINISHED'])->get() as $player) {
                            $player->forceFill(['participation_status' => 'FINISHED'])->save();
                            $this->roster->playerEvent('player_session.finished.v1', $actor, $session, $player);
                        }
                        $this->transition($actor, $session, 'FINISHED', 'finished', $input);
                        break;
                    case 'cancel':
                        $this->state($session, ['DRAFT', 'REGISTRATION', 'READY', 'RUNNING', 'PAUSED']);
                        $postStart = $session->started_at !== null;
                        if ($postStart) {
                            Gate::forUser($actor)->authorize('emergency', $session);
                            if (! ($input['emergency'] ?? false)) {
                                throw new ApiException('ACTION_NOT_ALLOWED', 'Explicit emergency cancellation is required.', [], 403);
                            }
                        }
                        $reason = GameInput::reason($input);
                        $resolution = ['status' => 'NOT_APPLICABLE'];
                        if ($postStart || $session->teams()->exists()) {
                            $resolution = $this->dependencies->cancellation->resolveCancellation($session, $reason, 'cancel:'.$session->public_id);
                            if (! in_array($resolution['status'] ?? null, ['RESOLVED', 'NOT_APPLICABLE'], true)) {
                                throw new ApiException('SESSION_NOT_READY', 'Cancellation resolution is pending.', [], 409);
                            }
                        }
                        $session->cancelled_at = now();
                        $this->transition($actor, $session, 'CANCELLED', 'cancelled', $input, ['reason' => $reason, 'cancelled_by' => $actor->public_id, 'post_start' => $postStart, 'financial_resolution_status' => $resolution['status']]);
                        break;
                }
                $result = $session->publicProfile($actor, $this->scope->operator($actor, $session));
                if ($extra !== []) {
                    $result = ['session' => $result] + $extra;
                }
                if ($key !== null) {
                    $this->idempotency->complete($key, $fingerprint, $action === 'create' ? 201 : 200, ['data' => $result]);
                }

                return $result;
            }, 3);
        } catch (\Throwable $exception) {
            if ($authorizedStart instanceof GameSession && $exception instanceof ApiException && $exception->errorCode !== 'IDEMPOTENCY_CONFLICT') {
                DB::transaction(fn () => $this->events->record('game_session.start_failed.v1', $actor, $authorizedStart, ['error_code' => $exception->errorCode], 'Start preconditions were not satisfied.'));
            }
            throw $exception;
        }
    }

    private function state(GameSession $session, array $allowed): void
    {
        if (! in_array($session->status, $allowed, true)) {
            throw new ApiException('STATE_TRANSITION_INVALID', 'The current Session state does not allow this action.', ['status' => $session->status], 409);
        }
    }

    private function configuration(GameSession $session, array $input): void
    {
        foreach (['timezone', 'registration_opens_at', 'registration_closes_at', 'scheduled_start_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $session->{$field} = $field === 'timezone' || $input[$field] === null ? $input[$field] : Carbon::parse($input[$field])->utc();
            }
        }
        if ($session->registration_opens_at && $session->registration_closes_at && $session->registration_opens_at->gte($session->registration_closes_at)) {
            throw ValidationException::withMessages(['registration_closes_at' => 'Registration must close after it opens.']);
        }
        if ($session->scheduled_start_at && $session->registration_closes_at && $session->scheduled_start_at->lt($session->registration_closes_at)) {
            throw ValidationException::withMessages(['scheduled_start_at' => 'Scheduled Start cannot precede registration close.']);
        }
    }

    private function transition(User $actor, GameSession $session, string $to, string $event, array $input, array $data = []): void
    {
        $from = $session->status;
        $transition = new GameSessionStateTransition;
        $transition->forceFill(['game_session_id' => $session->id, 'from_state' => $from, 'to_state' => $to, 'actor_type' => 'user', 'actor_id' => $actor->id, 'reason' => $input['reason'] ?? null, 'occurred_at' => now()])->save();
        $session->forceFill(['status' => $to])->save();
        if (in_array($to, ['PAUSED', 'RUNNING', 'FINISHED', 'CANCELLED'], true)) {
            app(RuntimeEngine::class)->sessionTransition($session, $to, $actor, (string) $transition->id, $input['reason'] ?? null);
        }
        $this->events->record('game_session.'.$event.'.v1', $actor, $session, $data + ['from_state' => $from, 'to_state' => $to], $input['reason'] ?? null);
    }

    private function bind(User $actor, GameSession $session, array $input, bool $rebind): void
    {
        $this->state($session, ['REGISTRATION', 'READY', 'RUNNING', 'PAUSED']);
        $target = $input['user_id'] ?? $actor->public_id;
        if ($target !== $actor->public_id) {
            if (! $rebind) {
                throw new ApiException('AUTHORIZATION_DENIED', 'Initial Device binding is a self-service action.', [], 403);
            }
            Gate::forUser($actor)->authorize('operate', [$session, $rebind ? 'rebindDevice' : 'bindDevice']);
            GameInput::reason($input);
        }
        $player = $session->players()->whereHas('user', fn (Builder $q): Builder => $q->where('public_id', $target))->whereNotIn('participation_status', ['REMOVED', 'DISQUALIFIED', 'FINISHED'])->first();
        if (! $player) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Player not found.', [], 404);
        }
        $user = User::whereKey($player->user_id)->lockForUpdate()->firstOrFail();
        $user->ensureActive();
        $device = $user->devices()->where('public_id', $input['device_id'])->whereNull('revoked_at')->lockForUpdate()->first();
        if (! $device) {
            throw new ApiException('DEVICE_NOT_BOUND', 'Device must be active and owned by this Player.', [], 409);
        }
        $old = $player->device?->public_id;
        if ($player->active_device_id === $device->id) {
            return;
        }
        if (! $rebind && $player->active_device_id !== null) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Use the audited rebind operation.', [], 409);
        }
        if ($rebind && $player->active_device_id === null) {
            throw new ApiException('DEVICE_NOT_BOUND', 'Bind a Device before rebinding.', [], 409);
        }
        if ($session->started_at !== null && $player->roster_frozen_at === null) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Player is not in the frozen roster.', [], 409);
        }
        if ($player->participation_status === 'READY') {
            $this->setReady($actor, $session, $player, false, $player->device);
        }
        $player->forceFill(['active_device_id' => $device->id])->save();
        $player->unsetRelation('device');
        $this->roster->playerEvent($rebind ? 'device.session_rebound.v1' : 'device.session_bound.v1', $actor, $session, $player, ['old_device_id' => $old, 'new_device_id' => $device->public_id, 'reason' => $input['reason'] ?? null], $input['reason'] ?? null);
        if ($session->started_at === null) {
            $this->roster->refreshReadiness($actor, $session, $player->registration);
        }
    }

    private function ready(User $actor, GameSession $session, array $input, ?UserDevice $requestDevice, bool $lateJoin): void
    {
        $this->state($session, $lateJoin ? ['RUNNING'] : ['REGISTRATION', 'READY']);
        $player = $session->players()->where('user_id', $actor->id)->whereIn('participation_status', ['JOINED', 'READY'])->first();
        if (! $player) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Active roster Player not found.', [], 404);
        }
        $device = $requestDevice ? UserDevice::whereKey($requestDevice->id)->where('user_id', $actor->id)->whereNull('revoked_at')->lockForUpdate()->first() : null;
        if (! $device || $player->active_device_id !== $device->id) {
            throw new ApiException('DEVICE_NOT_BOUND', 'Use the bound active Device.', [], 409);
        }
        if ($lateJoin && $player->roster_frozen_at === null) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Late join requires frozen participation.', [], 409);
        }
        $ready = $lateJoin || (bool) $input['ready'];
        if ($ready) {
            $this->dependencies->billingPass($session, [], $lateJoin ? 'late_join' : 'player_ready', $player);
        }
        if ($lateJoin) {
            $player->forceFill(['participation_status' => 'ACTIVE', 'joined_runtime_at' => now()])->save();
            $this->roster->playerEvent('player_session.late_joined.v1', $actor, $session, $player);
        } else {
            $this->setReady($actor, $session, $player, $ready, $device);
            $this->roster->refreshReadiness($actor, $session, $player->registration);
        }
    }

    private function setReady(User $actor, GameSession $session, GameSessionPlayer $player, bool $ready, UserDevice $device): void
    {
        $status = $ready ? 'READY' : 'JOINED';
        if ($player->participation_status === $status) {
            return;
        }
        $player->forceFill(['participation_status' => $status])->save();
        $event = new SessionReadyEvent;
        $event->forceFill(['game_session_player_id' => $player->id, 'device_id' => $device->id, 'ready' => $ready, 'occurred_at' => now()])->save();
        $this->roster->playerEvent($ready ? 'game_session.player_ready.v1' : 'game_session.player_unready.v1', $actor, $session, $player);
        if ($ready) {
            $this->roster->playerEvent('player_session.ready.v1', $actor, $session, $player);
        }
    }

    /** Service-only pre-start selection; no undocumented roster HTTP route. */
    private function syncRoster(User $actor, GameSession $session, string $teamId, array $input): void
    {
        $this->state($session, ['REGISTRATION', 'READY']);
        $team = $this->roster->team($actor, $session, $teamId, $input);
        $registration = $session->teams()->where('team_id', $team->id)->whereIn('status', ['REGISTERED', 'READY'])->first();
        if (! $registration) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Registration not found.', [], 404);
        }
        $members = $team->activeMembers()->whereHas('user', fn (Builder $q): Builder => $q->whereIn('public_id', $input['user_ids']))->with('user')->get();
        if ($members->count() !== count($input['user_ids'])) {
            throw new ApiException('TEAM_NOT_ELIGIBLE', 'Roster additions require active membership.', [], 409);
        }
        $selected = $members->pluck('user_id')->all();
        foreach ($registration->players()->whereNotIn('participation_status', ['REMOVED', 'DISQUALIFIED'])->get() as $player) {
            if (! in_array($player->user_id, $selected, true)) {
                $this->roster->removePlayer($actor, $session, $player, $input['reason'] ?? null);
            }
        }
        foreach ($members as $member) {
            $existing = $registration->players()->where('user_id', $member->user_id)->first();
            if ($existing && in_array($existing->participation_status, ['REMOVED', 'DISQUALIFIED'], true)) {
                throw new ApiException('ACTION_NOT_ALLOWED', 'Removed participation is terminal.', [], 409);
            }
            if (! $existing) {
                $this->roster->addPlayer($actor, $session, $registration, $member->user);
            }
        }
        $this->roster->refreshReadiness($actor, $session, $registration);
    }

    private function start(User $actor, GameSession $session, array $input): array
    {
        $this->state($session, ['READY']);
        $context = $this->roster->context($session, true);
        $billing = $this->dependencies->billingPass($session, $context, 'start');
        $assignments = $this->dependencies->assignments($session, $context);
        $pricing = $this->dependencies->pricing->freeze($session, $context);
        if ($pricing === [] || ! is_string($billing['reference'] ?? null) || $billing['reference'] === '') {
            throw new ApiException('SESSION_BILLING_NOT_READY', 'Immutable billing/pricing references are required.', [], 409);
        }
        foreach ($context as &$teamContext) {
            foreach ($teamContext['players'] as &$playerContext) {
                $reference = $billing['participation_references'][$playerContext['user_id']] ?? null;
                if (! is_string($reference) || $reference === '') {
                    throw new ApiException('SESSION_BILLING_NOT_READY', 'Every frozen Player requires a billable participation reference.', [], 409);
                }
                $playerContext['billable_participation_ref'] = $reference;
                $playerContext['membership_context'] = ['team_id' => $teamContext['team_id'], 'source' => 'session_roster', 'joined_at' => $playerContext['joined_at']];
            }
            unset($playerContext);
        }
        unset($teamContext);
        $frozenAt = now()->startOfSecond();
        $frozen = [];
        foreach ($context as $team) {
            $registration = $session->teams()->whereHas('team', fn (Builder $q): Builder => $q->where('public_id', $team['team_id']))->firstOrFail();
            $snapshot = $team + ['version' => 1, 'frozen_at' => $frozenAt->toISOString(), 'billing_reference' => $billing['reference'], 'pricing_references' => $pricing, 'scenario_version_ref' => $assignments[$team['team_id']]];
            $record = new GameSessionRosterSnapshot;
            $record->forceFill(['game_session_team_id' => $registration->id, 'version' => 1, 'snapshot_json' => $snapshot, 'frozen_at' => $frozenAt, 'created_at' => $frozenAt])->save();
            $frozen[] = $snapshot;
            $registration->players()->whereNotIn('participation_status', ['REMOVED', 'DISQUALIFIED'])->update(['roster_frozen_at' => $frozenAt]);
        }
        $runtime = $this->dependencies->runtime->bootstrap($session, $frozen, 'start:'.$session->public_id);
        if (array_diff(array_column($frozen, 'team_id'), array_keys($runtime)) || count($runtime) !== count($frozen) || collect($runtime)->contains(fn ($reference): bool => ! is_string($reference) || $reference === '')) {
            throw new ApiException('SESSION_NOT_READY', 'Runtime bootstrap did not return every Team reference.', [], 409);
        }
        foreach ($session->teams()->whereIn('status', ['REGISTERED', 'READY'])->get() as $team) {
            $team->forceFill(['status' => 'ACTIVE'])->save();
            $this->events->record('session_team.activated.v1', $actor, $session, ['team_id' => $team->team->public_id]);
        }
        foreach ($session->players()->where('participation_status', 'READY')->get() as $player) {
            $player->forceFill(['participation_status' => 'ACTIVE', 'joined_runtime_at' => now()])->save();
            $this->roster->playerEvent('player_session.activated.v1', $actor, $session, $player);
        }
        $session->started_at = $frozenAt;
        $this->events->record('game_session.billing_validated.v1', $actor, $session, ['billing_reference' => $billing['reference']]);
        $this->transition($actor, $session, 'RUNNING', 'started', $input, ['started_at' => $session->started_at->toISOString(), 'team_count' => count($context), 'player_count' => array_sum(array_map(fn (array $t): int => count($t['players']), $context)), 'roster_snapshot_version' => 1, 'pricing_snapshot_ref' => $pricing, 'scenario_assignments_summary' => $assignments]);

        return ['frozen_rosters' => $frozen, 'runtime_instances' => $runtime, 'started_at' => $session->started_at->toISOString()];
    }
}

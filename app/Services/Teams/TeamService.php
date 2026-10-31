<?php

namespace App\Services\Teams;

use App\Exceptions\ApiException;
use App\Models\Game;
use App\Models\GameBooking;
use App\Models\GameSession;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\TeamJoinRequest;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Idempotency\IdempotencyStore;
use App\Services\Organizations\OrganizationContext;
use App\Services\Organizations\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamService
{
    public function __construct(private TeamScope $scope, private OrganizationScope $organizations, private TeamEvents $events, private IdempotencyStore $idempotency, private AuditService $audit) {}

    public function listing(User $actor, array $input = []): CursorPaginator
    {
        $input = TeamInput::validate($input, 'index');

        $query = $this->scope->teams($actor)->with(['captain', 'organization', 'activeMembers.user']);
        if (! empty($input['q'])) {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($input['q'])).'%';
            $query->where(function (Builder $teams) use ($needle): void {
                $teams->whereRaw('lower(name) LIKE lower(?)', [$needle])
                    ->orWhereHas('captain', fn (Builder $captain): Builder => $captain->whereRaw('lower(name) LIKE lower(?)', [$needle]));
            });
        }
        if (! empty($input['status'])) {
            $query->where('status', $input['status']);
        }

        return $query->orderBy('public_id')
            ->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);
    }

    public function show(User $actor, string $teamId): array
    {
        return $this->scope->resolve($actor, $teamId)->publicProfile();
    }

    public function findPlayer(User $actor, string $playerId): array
    {
        $actor->ensureActive();
        $player = Str::isUuid($playerId) ? User::query()->where('public_id', $playerId)->where('status', 'ACTIVE')->first() : null;
        if (! $player) {
            throw $this->organizations->notFound();
        }

        return ['id' => $player->public_id, 'name' => $player->name];
    }

    public function previewInvite(User $actor, string $code): array
    {
        $actor->ensureActive();
        $invite = TeamInvite::query()->where('code_hash', hash('sha256', $code))->with('team.organization')->first();
        if (! $invite || $invite->recipient_user_id !== null) {
            throw $this->organizations->notFound();
        }
        $team = $invite->team;
        if ($team->organization_id !== null) {
            $context = $this->organizations->resolve($actor, $team->organization->public_id, true);
            $context->scope(Team::query())->whereKey($team->id)->firstOrFail();
        }
        $this->validateCodeInvite($team, $invite);
        $this->ensureTeamCanAcceptMembers($team);
        $memberCount = $team->activeMembers()->count();
        if ($memberCount >= 8) {
            throw new ApiException('TEAM_FULL', 'The team already has eight active members.', [], 409);
        }

        return [
            'team' => ['name' => $team->name, 'status' => $team->status, 'captain' => ['name' => $team->captain->name]],
            'member_count' => $memberCount,
            'capacity' => 8,
            'expires_at' => $invite->expires_at?->toISOString(),
        ];
    }

    public function requestTeamJoin(User $actor, string $code): array
    {
        return DB::transaction(function () use ($actor, $code): array {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $lookup = TeamInvite::query()->where('code_hash', hash('sha256', $code))->first();
            if (! $lookup || $lookup->recipient_user_id !== null) {
                throw $this->organizations->notFound();
            }
            $team = Team::query()->whereKey($lookup->team_id)->lockForUpdate()->firstOrFail();
            if ($team->organization_id !== null) {
                $context = $this->organizations->resolve($actor, $team->organization->public_id, true);
                $context->scope(Team::query())->whereKey($team->id)->firstOrFail();
            }
            $invite = TeamInvite::query()->whereKey($lookup->id)->lockForUpdate()->firstOrFail();
            $this->validateCodeInvite($team, $invite);
            if ($team->activeMembers()->where('user_id', $actor->id)->exists()) {
                throw new ApiException('ALREADY_TEAM_MEMBER', 'You are already an active member.', [], 409);
            }
            if ($team->activeMembers()->count() >= 8) {
                throw new ApiException('TEAM_FULL', 'The team already has eight active members.', [], 409);
            }
            $this->ensureTeamCanAcceptMembers($team);
            $existing = TeamJoinRequest::query()->where('team_invite_id', $invite->id)->where('user_id', $actor->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->status === 'PENDING' && ! $existing->expires_at?->isPast()) {
                    return ['id' => $existing->public_id, 'status' => 'PENDING', 'team_name' => $team->name];
                }
                if ($existing->status === 'ACCEPTED') {
                    throw new ApiException('ALREADY_TEAM_MEMBER', 'You are already an active member.', [], 409);
                }
                throw new ApiException('JOIN_REQUEST_ALREADY_DECIDED', 'A join request has already been decided for this invitation.', ['status' => $existing->status], 409);
            }
            $request = new TeamJoinRequest;
            $request->forceFill(['team_id' => $team->id, 'team_invite_id' => $invite->id, 'user_id' => $actor->id, 'status' => 'PENDING', 'expires_at' => $invite->expires_at])->save();

            return ['id' => $request->public_id, 'status' => 'PENDING', 'team_name' => $team->name];
        }, 3);
    }

    public function joinRequests(User $actor, string $teamId): array
    {
        $actor->ensureActive();
        $team = $this->scope->resolve($actor, $teamId);
        Gate::forUser($actor)->authorize('captain', $team);

        return TeamJoinRequest::query()->where('team_id', $team->id)->with('user')->orderByDesc('created_at')->limit(100)->get()->map(fn (TeamJoinRequest $request): array => $request->publicProfile())->all();
    }

    public function decideJoinRequest(User $actor, string $teamId, string $requestId, bool $accept): array
    {
        return DB::transaction(function () use ($actor, $teamId, $requestId, $accept): array {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $resolved = $this->scope->resolve($actor, $teamId);
            $team = $this->scope->lock($actor, $resolved);
            Gate::forUser($actor)->authorize('captain', $team);
            $requestLookup = Str::isUuid($requestId) ? TeamJoinRequest::query()->where('public_id', $requestId)->where('team_id', $team->id)->first() : null;
            if (! $requestLookup) {
                throw $this->organizations->notFound();
            }
            $invite = TeamInvite::query()->whereKey($requestLookup->team_invite_id)->lockForUpdate()->firstOrFail();
            $request = TeamJoinRequest::query()->whereKey($requestLookup->id)->lockForUpdate()->firstOrFail();
            if ($request->status === ($accept ? 'ACCEPTED' : 'DECLINED')) {
                return ['id' => $request->public_id, 'status' => $request->status];
            }
            if ($request->status !== 'PENDING') {
                throw new ApiException('JOIN_REQUEST_NOT_PENDING', 'This join request is no longer pending.', ['status' => $request->status], 409);
            }
            if ($request->expires_at?->lessThanOrEqualTo(now())) {
                throw new ApiException('INVITE_EXPIRED', 'The invitation has expired.', [], 409);
            }
            if ($accept) {
                $this->ensureActive($team);
                $this->validateCodeInvite($team, $invite);
                $this->ensureTeamCanAcceptMembers($team);
                if ($team->activeMembers()->count() >= 8) {
                    throw new ApiException('TEAM_FULL', 'The team already has eight active members.', [], 409);
                }
                if ($team->activeMembers()->where('user_id', $request->user_id)->exists()) {
                    throw new ApiException('ALREADY_TEAM_MEMBER', 'This player is already an active member.', [], 409);
                }
                $joiningUser = User::query()->whereKey($request->user_id)->lockForUpdate()->firstOrFail();
                $joiningUser->ensureActive();
                $this->resolveBookingRosterChanges($joiningUser, $team);
                $this->addMembership($team, $joiningUser);
                $invite->increment('uses_count');
                $this->events->record('team.member_joined.v1', $joiningUser, $team, target: $joiningUser);
            }
            $request->forceFill(['status' => $accept ? 'ACCEPTED' : 'DECLINED', 'decided_by' => $actor->id, 'decided_at' => now()])->save();

            return ['id' => $request->public_id, 'status' => $request->status];
        }, 3);
    }

    private function validateCodeInvite(Team $team, TeamInvite $invite): void
    {
        $this->ensureActive($team);
        if ($invite->revoked_at !== null) {
            throw new ApiException('INVITE_REVOKED', 'This invitation has been revoked.', [], 409);
        }
        if ($invite->expires_at !== null && $invite->expires_at->lessThanOrEqualTo(now())) {
            throw new ApiException('INVITE_EXPIRED', 'This invitation has expired.', [], 409);
        }
        if ($invite->max_uses !== null && $invite->uses_count >= $invite->max_uses) {
            throw new ApiException('INVITE_EXHAUSTED', 'This invitation has no remaining uses.', [], 409);
        }
    }

    private function ensureTeamCanAcceptMembers(Team $team): void
    {
        $hasBlockingBooking = GameBooking::query()->where('team_id', $team->id)
            ->whereIn('status', ['AWAITING_PAYMENT', 'PARTIALLY_PAID', 'PAID', 'ROOM_CREATED', 'REFUND_PENDING'])
            ->exists();
        if ($hasBlockingBooking) {
            throw new ApiException('TEAM_LOCKED', 'Team membership is locked while a booking is active.', [], 409);
        }
    }

    /** @return list<array<string, mixed>> */
    public function incomingInvitations(User $actor): array
    {
        $actor->ensureActive();

        return TeamInvite::query()->where('recipient_user_id', $actor->id)->whereNotNull('decision')
            ->with(['team.captain'])->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (TeamInvite $invite): array => [
                'id' => $invite->public_id,
                'team' => ['id' => $invite->team->public_id, 'name' => $invite->team->name],
                'captain' => ['id' => $invite->team->captain->public_id, 'name' => $invite->team->captain->name],
                'status' => $invite->revoked_at !== null ? 'REVOKED' : ($invite->decision === 'PENDING' && $invite->expires_at?->isPast() ? 'EXPIRED' : $invite->decision),
                'expires_at' => $invite->expires_at?->toISOString(),
                'created_at' => $invite->created_at?->toISOString(),
            ])->all();
    }

    public function invitePlayer(User $actor, string $teamId, string $playerId, ?string $expiresAt = null): array
    {
        return DB::transaction(function () use ($actor, $teamId, $playerId, $expiresAt): array {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $resolved = $this->scope->resolve($actor, $teamId);
            $team = $this->scope->lock($actor, $resolved);
            $this->ensureActive($team);
            Gate::forUser($actor)->authorize('invites', $team);
            $recipient = Str::isUuid($playerId) ? User::query()->where('public_id', $playerId)->lockForUpdate()->first() : null;
            if (! $recipient || $recipient->status !== 'ACTIVE') {
                throw $this->organizations->notFound();
            }
            if ($recipient->id === $actor->id || $team->activeMembers()->where('user_id', $recipient->id)->exists()) {
                throw new ApiException('ALREADY_TEAM_MEMBER', 'This player already belongs to the team.', [], 409);
            }
            if ($team->activeMembers()->count() >= 8) {
                throw new ApiException('TEAM_FULL', 'The team already has eight active members.', [], 409);
            }
            $pending = $team->invites()->where('recipient_user_id', $recipient->id)->where('decision', 'PENDING')->whereNull('revoked_at')->lockForUpdate()->first();
            if ($pending && $pending->expires_at?->isPast()) {
                $pending->forceFill(['decision' => 'EXPIRED', 'responded_at' => now()])->save();
                $pending = null;
            }
            if ($pending) {
                return $pending->loadMissing('recipient')->publicProfile();
            }

            $invite = new TeamInvite;
            $invite->forceFill([
                'team_id' => $team->id,
                'code_hash' => hash('sha256', Str::random(64)),
                'created_by' => $actor->id,
                'recipient_user_id' => $recipient->id,
                'expires_at' => $expiresAt ?? now()->addDays(14),
                'max_uses' => 1,
                'uses_count' => 0,
                'decision' => 'PENDING',
            ])->save();
            $this->events->record('team.invite_created.v1', $actor, $team, ['invite_id' => $invite->public_id], target: $recipient);

            return $invite->publicProfile();
        }, 3);
    }

    public function respondToInvitation(User $actor, string $inviteId, bool $accept): array
    {
        return DB::transaction(function () use ($actor, $inviteId, $accept): array {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $lookup = Str::isUuid($inviteId) ? TeamInvite::query()->where('public_id', $inviteId)->where('recipient_user_id', $actor->id)->first() : null;
            if (! $lookup) {
                throw $this->organizations->notFound();
            }
            $teamQuery = Team::query()->with('organization')->whereKey($lookup->team_id);
            if ($accept && $lookup->team->organization_id !== null) {
                $context = $this->organizations->resolve($actor, $lookup->team->organization->public_id, true);
                $context->scope(Team::query())->whereKey($lookup->team_id)->firstOrFail();
            }
            $team = $teamQuery->lockForUpdate()->firstOrFail();
            $invite = TeamInvite::query()->whereKey($lookup->id)->lockForUpdate()->firstOrFail();
            if ($invite->decision === ($accept ? 'ACCEPTED' : 'DECLINED')) {
                return ['id' => $invite->public_id, 'status' => $invite->decision, 'team' => $accept ? $team->publicProfile() : null];
            }
            if ($invite->decision !== 'PENDING') {
                throw new ApiException('INVITATION_NOT_PENDING', 'This invitation is no longer awaiting a response.', ['status' => $invite->decision], 409);
            }
            if ($invite->revoked_at !== null) {
                throw new ApiException('INVITE_REVOKED', 'This invitation has been revoked.', [], 409);
            }
            if ($invite->expires_at?->lessThanOrEqualTo(now())) {
                $invite->forceFill(['decision' => 'EXPIRED', 'responded_at' => now()])->save();

                return ['id' => $invite->public_id, 'status' => 'EXPIRED', 'team' => null];
            }
            $this->ensureActive($team);
            if ($accept) {
                if ($team->activeMembers()->where('user_id', $actor->id)->exists()) {
                    throw new ApiException('ALREADY_TEAM_MEMBER', 'You already belong to this team.', [], 409);
                }
                if ($team->activeMembers()->count() >= 8) {
                    throw new ApiException('TEAM_FULL', 'The team already has eight active members.', [], 409);
                }
                $this->resolveBookingRosterChanges($actor, $team);
                $this->addMembership($team, $actor);
                $invite->forceFill(['decision' => 'ACCEPTED', 'responded_at' => now(), 'uses_count' => 1])->save();
                $this->events->record('team.member_joined.v1', $actor, $team, target: $actor);
            } else {
                $invite->forceFill(['decision' => 'DECLINED', 'responded_at' => now()])->save();
            }
            $this->audit->record($accept ? 'team.invite_accepted' : 'team.invite_declined', 'team_invite', $invite->public_id,
                context: ['team_id' => $team->public_id], actorId: $actor->id);

            return ['id' => $invite->public_id, 'status' => $invite->decision, 'team' => $accept ? $team->fresh()->publicProfile() : null];
        }, 3);
    }

    public function invites(User $actor, string $teamId, array $input = []): CursorPaginator
    {
        $team = $this->scope->resolve($actor, $teamId);
        Gate::forUser($actor)->authorize('invites', $team);
        $input = TeamInput::validate($input, 'invites');

        return $team->invites()->with('recipient')->orderBy('public_id')->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);
    }

    /**
     * All mutations serialize on actor, then organization (when present), then team, then invite.
     * Idempotency reservation, state, history, audit and outbox commit together.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    public function execute(User $actor, string $action, array $input = [], ?string $teamId = null, ?string $targetId = null, ?string $idempotencyKey = null): ?array
    {
        if (! in_array($action, ['create', 'update', 'join', 'leave', 'remove', 'transfer', 'archive', 'createInvite', 'revokeInvite'], true)) {
            throw new \InvalidArgumentException('Unknown team operation.');
        }

        return DB::transaction(function () use ($actor, $action, $input, $teamId, $targetId, $idempotencyKey): ?array {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $team = null;
            if ($teamId !== null) {
                $resolvedTeam = $this->scope->resolve($actor, $teamId);
                if ($action === 'archive') {
                    $this->lockBlockingSessions($resolvedTeam);
                }
                $team = $this->scope->lock($actor, $resolvedTeam);
            }
            $input = TeamInput::validate($input, $action);
            $key = null;
            $fingerprint = '';
            if ($idempotencyKey !== null) {
                Validator::make(['key' => $idempotencyKey], ['key' => ['required', 'string', 'min:8', 'max:255']])->validate();
                if ($action === 'createInvite') {
                    throw ValidationException::withMessages(['Idempotency-Key' => 'Invite secrets are returned only once; this operation does not accept an idempotency key.']);
                }
                $key = 'teams:'.$actor->public_id.':'.hash('sha256', $idempotencyKey);
                $canonical = $input;
                ksort($canonical);
                $fingerprint = hash('sha256', json_encode([$action, $teamId, $targetId, $canonical], JSON_THROW_ON_ERROR));
                $reservation = $this->idempotency->reserve($key, $fingerprint);
                if ($reservation['status'] === 'completed') {
                    $result = $reservation['response'];
                    if (isset($result['data']['id']) && $action === 'create') {
                        $this->scope->resolve($actor, $result['data']['id']);
                    }

                    return $result['data'];
                }
                if ($reservation['status'] !== 'reserved') {
                    throw new ApiException('CONFLICT', 'The request is still processing.', [], 409);
                }
            }
            $result = match ($action) {
                'create' => $this->create($actor, $input)->publicProfile(),
                'join' => $this->requestTeamJoin($actor, $input['invite_code']),
                default => $this->mutate($actor, $team ?? throw $this->organizations->notFound(), $action, $input, $targetId),
            };
            if ($key !== null) {
                $this->idempotency->complete($key, $fingerprint, $action === 'create' ? 201 : 200, ['data' => $result]);
            }

            return $result;
        }, 3);
    }

    /** Organization association is service-only and requires an authorized existing tenant context. */
    public function createForOrganization(User $actor, OrganizationContext $context, array $input): Team
    {
        return DB::transaction(function () use ($actor, $context, $input): Team {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $trusted = $this->organizations->resolve($actor, $context->organization->public_id, true);
            $trusted->authorize('update');
            if ($trusted->organization->status !== 'ACTIVE') {
                throw new ApiException('STATE_TRANSITION_INVALID', 'The organization is archived.', [], 409);
            }

            return $this->create($actor, TeamInput::validate($input, 'create'), $trusted->organization->id);
        }, 3);
    }

    private function create(User $actor, array $input, ?int $organizationId = null): Team
    {
        Gate::forUser($actor)->authorize('create', Team::class);
        $team = new Team(['name' => $input['name']]);
        $team->forceFill(['captain_user_id' => $actor->id, 'organization_id' => $organizationId])->save();
        $this->addMembership($team, $actor);
        $this->events->record('team.created.v1', $actor, $team, ['name' => $team->name, 'captain_user_id' => $actor->public_id]);
        $this->events->record('team.member_joined.v1', $actor, $team, target: $actor);

        return $team;
    }

    private function mutate(User $actor, Team $team, string $action, array $input, ?string $targetId): ?array
    {
        $ability = match ($action) {
            'createInvite', 'revokeInvite' => 'invites',
            default => $action,
        };
        Gate::forUser($actor)->authorize($ability, $team);
        $reason = $input['reason'] ?? null;
        if (! Gate::forUser($actor)->allows('captain', $team) && $action !== 'leave') {
            if (! is_string($reason) || trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => 'A reason is required for privileged team intervention.']);
            }
        }
        if ($action === 'archive' && $team->status === 'ARCHIVED') {
            return $team->publicProfile();
        }
        if ($action !== 'revokeInvite') {
            $this->ensureActive($team);
        }
        if (in_array($action, ['leave', 'remove', 'transfer', 'archive'], true)) {
            $this->resolveBookingRosterChanges($actor, $team);
        }
        switch ($action) {
            case 'update':
                $before = $team->name;
                $team->name = $input['name'];
                if ($team->isDirty()) {
                    $team->save();
                    $this->events->record('team.updated.v1', $actor, $team, ['before' => ['name' => $before], 'after' => ['name' => $team->name]], $reason);
                }
                break;
            case 'createInvite':
                $code = Str::random(16);
                $invite = new TeamInvite;
                $invite->forceFill(['team_id' => $team->id, 'code_hash' => hash('sha256', $code), 'created_by' => $actor->id, 'expires_at' => $input['expires_at'] ?? null, 'max_uses' => $input['max_uses'] ?? null, 'uses_count' => 0])->save();
                $this->events->record('team.invite_created.v1', $actor, $team, ['invite_id' => $invite->public_id], $reason);

                return $invite->publicProfile() + ['invite_code' => $code];
            case 'revokeInvite':
                $invite = Str::isUuid($targetId ?? '') ? $team->invites()->where('public_id', $targetId)->lockForUpdate()->first() : null;
                if (! $invite) {
                    throw $this->organizations->notFound();
                }
                if ($invite->revoked_at === null) {
                    $invite->revoked_at = now();
                    $invite->save();
                    $this->events->record('team.invite_revoked.v1', $actor, $team, ['invite_id' => $invite->public_id], $reason);
                }

                return null;
            case 'leave':
            case 'remove':
                $member = $this->activeMember($team, $action === 'leave' ? $actor->public_id : ($targetId ?? ''));
                if ($member->user_id === $team->captain_user_id) {
                    throw new ApiException('ACTION_NOT_ALLOWED', 'Transfer captaincy before leaving or removing the captain.', [], 409);
                }
                $member->forceFill(['status' => $action === 'leave' ? 'LEFT' : 'REMOVED', 'left_at' => now(), 'removed_by' => $action === 'remove' ? $actor->id : null])->save();
                $this->events->record($action === 'leave' ? 'team.member_left.v1' : 'team.member_removed.v1', $actor, $team, reason: $reason, target: $member->user);

                return null;
            case 'transfer':
                $member = $this->activeMember($team, $input['user_id']);
                if ($team->captain_user_id === $member->user_id) {
                    throw new ApiException('ACTION_NOT_ALLOWED', 'The target is already captain.', [], 409);
                }
                $oldCaptain = $team->captain->public_id;
                $team->captain_user_id = $member->user_id;
                $team->save();
                $team->unsetRelation('captain');
                $this->events->record('team.captain_changed.v1', $actor, $team, ['old_captain_user_id' => $oldCaptain, 'new_captain_user_id' => $member->user->public_id], $reason, $member->user);
                break;
            case 'archive':
                $this->ensureNoBlockingSessions($team);
                $team->forceFill(['status' => 'ARCHIVED', 'archived_at' => now()])->save();
                $this->events->record('team.archived.v1', $actor, $team, reason: $reason);
                break;
        }

        return $team->publicProfile();
    }

    private function activeMember(Team $team, string $userId): TeamMember
    {
        $member = Str::isUuid($userId) ? $team->activeMembers()->whereHas('user', fn (Builder $query): Builder => $query->where('public_id', $userId))->lockForUpdate()->first() : null;
        if (! $member) {
            throw $this->organizations->notFound();
        }

        return $member;
    }

    private function resolveBookingRosterChanges(User $actor, Team $team): void
    {
        $bookings = GameBooking::query()->where('team_id', $team->id)
            ->whereIn('status', ['AWAITING_PAYMENT', 'PARTIALLY_PAID', 'PAID', 'ROOM_CREATED', 'REFUND_PENDING'])
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($bookings as $booking) {
            if ($booking->status === 'AWAITING_PAYMENT') {
                $booking->paymentOrders()->whereIn('status', ['PENDING', 'PROCESSING'])->update(['status' => 'CANCELLED', 'updated_at' => now()]);
                $booking->participants()->where('status', 'PENDING')->update(['status' => 'CANCELLED', 'updated_at' => now()]);
                $booking->forceFill(['status' => 'CANCELLED', 'updated_at' => now()])->save();
                $this->audit->record('game_booking.cancelled_roster_change', 'game_booking', $booking->public_id,
                    context: ['team_id' => $team->public_id], actorId: $actor->id);

                continue;
            }
            if (in_array($booking->status, ['PARTIALLY_PAID', 'PAID', 'REFUND_PENDING'], true)) {
                throw new ApiException('BOOKING_PAYMENT_REQUIRES_RESOLUTION', 'Resolve the existing booking payment before changing team membership.', ['booking_id' => $booking->public_id, 'status' => $booking->status], 409);
            }
            $session = GameSession::query()->where('game_id', $booking->game_id)
                ->whereHas('teams', fn (Builder $query): Builder => $query->where('team_id', $team->id))
                ->orderByDesc('created_at')->first();
            if (! $session || ! in_array($session->status, ['FINISHED', 'CANCELLED'], true)) {
                throw new ApiException('TEAM_HAS_ACTIVE_SESSION', 'Team membership cannot change until the booked game session is finished or cancelled.', ['booking_id' => $booking->public_id], 409);
            }
        }
    }

    private function addMembership(Team $team, User $user): void
    {
        $member = new TeamMember;
        $member->forceFill(['team_id' => $team->id, 'user_id' => $user->id, 'status' => 'ACTIVE', 'joined_at' => now()])->save();
    }

    private function ensureActive(Team $team): void
    {
        if ($team->status !== 'ACTIVE' || ($team->organization_id !== null && $team->organization->status !== 'ACTIVE')) {
            throw new ApiException('STATE_TRANSITION_INVALID', 'The team or its organization is archived.', [], 409);
        }
    }

    /** Lock sessions before the team, matching SessionService's session-then-team order. */
    private function lockBlockingSessions(Team $team): void
    {
        if ($team->organization_id !== null) {
            Organization::query()->whereKey($team->organization_id)->lockForUpdate()->firstOrFail();
        }
        $relations = DB::table('game_session_teams')->join('game_sessions', 'game_sessions.id', '=', 'game_session_teams.game_session_id')
            ->where('game_session_teams.team_id', $team->id)
            ->select(['game_sessions.organization_id', 'game_sessions.game_id', 'game_sessions.id'])->distinct()->orderBy('game_sessions.organization_id')
            ->get();
        $organizationIds = $relations->pluck('organization_id')->unique()->sort()->values();
        $gameIds = $relations->pluck('game_id')->unique()->sort()->values();
        $sessionIds = $relations->pluck('id')->unique()->sort()->values();

        if ($organizationIds->isNotEmpty()) {
            Organization::query()->whereIn('id', $organizationIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }
        if ($gameIds->isNotEmpty()) {
            Game::query()->whereIn('id', $gameIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }
        if ($sessionIds->isNotEmpty()) {
            GameSession::query()->whereIn('id', $sessionIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    private function ensureNoBlockingSessions(Team $team): void
    {
        $hasBlockingSession = GameSession::query()
            ->whereIn('status', ['REGISTRATION', 'READY', 'RUNNING', 'PAUSED'])
            ->whereHas('teams', fn (Builder $query): Builder => $query->where('team_id', $team->id))
            ->exists();

        if ($hasBlockingSession) {
            throw new ApiException('STATE_TRANSITION_INVALID', 'A team associated with an active or pending session cannot be archived.', [], 409);
        }
    }
}

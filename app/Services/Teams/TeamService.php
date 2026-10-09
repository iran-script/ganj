<?php

namespace App\Services\Teams;

use App\Exceptions\ApiException;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\TeamMember;
use App\Models\User;
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
    public function __construct(private TeamScope $scope, private OrganizationScope $organizations, private TeamEvents $events, private IdempotencyStore $idempotency) {}

    public function listing(User $actor, array $input = []): CursorPaginator
    {
        $input = TeamInput::validate($input, 'index');

        return $this->scope->teams($actor)->with(['captain', 'organization', 'activeMembers.user'])->orderBy('public_id')
            ->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);
    }

    public function show(User $actor, string $teamId): array
    {
        return $this->scope->resolve($actor, $teamId)->publicProfile();
    }

    public function invites(User $actor, string $teamId, array $input = []): CursorPaginator
    {
        $team = $this->scope->resolve($actor, $teamId);
        Gate::forUser($actor)->authorize('invites', $team);
        $input = TeamInput::validate($input, 'invites');

        return $team->invites()->orderBy('public_id')->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);
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
            $team = $teamId === null ? null : $this->scope->lock($actor, $this->scope->resolve($actor, $teamId));
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
                    if (isset($result['data']['id']) && in_array($action, ['create', 'join'], true)) {
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
                'join' => $this->join($actor, $input['invite_code'])->publicProfile(),
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

    private function join(User $actor, string $code): Team
    {
        $invite = TeamInvite::query()->where('code_hash', hash('sha256', $code))->first();
        if (! $invite) {
            throw $this->organizations->notFound();
        }
        $team = $invite->team;
        if ($team->organization_id !== null) {
            $context = $this->organizations->resolve($actor, $team->organization->public_id, true);
            $context->scope(Team::query())->whereKey($team->id)->firstOrFail();
            if ($context->organization->status !== 'ACTIVE') {
                throw new ApiException('STATE_TRANSITION_INVALID', 'The organization is archived.', [], 409);
            }
        }
        $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
        $invite = $team->invites()->whereKey($invite->id)->lockForUpdate()->firstOrFail();
        $this->ensureActive($team);
        if ($invite->revoked_at !== null) {
            throw new ApiException('INVITE_REVOKED', 'This invitation has been revoked.', [], 409);
        }
        if ($invite->expires_at !== null && $invite->expires_at->lessThanOrEqualTo(now())) {
            throw new ApiException('INVITE_EXPIRED', 'This invitation has expired.', [], 409);
        }
        if ($team->activeMembers()->where('user_id', $actor->id)->exists()) {
            throw new ApiException('ALREADY_TEAM_MEMBER', 'You are already an active member.', [], 409);
        }
        if ($invite->max_uses !== null && $invite->uses_count >= $invite->max_uses) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'This invitation has no remaining uses.', [], 409);
        }
        if ($team->activeMembers()->count() >= 8) {
            throw new ApiException('TEAM_FULL', 'The team already has eight active members.', [], 409);
        }
        $this->addMembership($team, $actor);
        $invite->increment('uses_count');
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
                $code = bin2hex(random_bytes(32));
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
}

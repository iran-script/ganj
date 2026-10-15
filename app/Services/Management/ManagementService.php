<?php

namespace App\Services\Management;

use App\Enums\OrganizationRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\RoleAssignment;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Organizations\GlobalRoleService;
use App\Services\Organizations\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManagementService
{
    public function __construct(
        private OrganizationScope $organizations,
        private GlobalRoleService $globalRoles,
        private AuditService $audit,
    ) {}

    public function users(User $actor, array $input): CursorPaginator
    {
        Gate::forUser($actor)->authorize('admin.users.view');
        $query = User::query()->with(['globalRoleAssignments' => fn (HasMany $roles) => $roles->where('role_code', RoleAssignment::SUPER_ADMIN)->whereNull('organization_id')->whereNull('resource_type')->whereNull('resource_id')->whereNull('revoked_at'), 'organizationMemberships.organization', 'teamMemberships.team', 'gameSessionParticipations.session', 'gameSessionParticipations.registration.team']);
        $this->searchUsers($query, $input['q'] ?? null);
        if (isset($input['status'])) {
            $query->where('status', $input['status']);
        }
        if (isset($input['role'])) {
            if ($input['role'] !== RoleAssignment::SUPER_ADMIN) {
                throw ValidationException::withMessages(['role' => 'Only the existing global role can be filtered here.']);
            }
            $query->whereHas('globalRoleAssignments', fn (Builder $roles) => $roles->where('role_code', $input['role'])->whereNull('revoked_at'));
        }
        if (isset($input['organization_id'])) {
            $organization = Organization::query()->where('public_id', $input['organization_id'])->first();
            if (! $organization) {
                throw $this->organizations->notFound();
            }
            $query->whereHas('organizationMemberships', fn (Builder $members) => $members->where('organization_id', $organization->id)->where('status', 'ACTIVE'));
        }
        $query->orderBy('public_id');

        return $query->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);
    }

    public function user(User $actor, string $userId): array
    {
        Gate::forUser($actor)->authorize('admin.users.view');
        $user = $this->findUser($userId)->load([
            'globalRoleAssignments' => fn (HasMany $roles) => $roles->where('role_code', RoleAssignment::SUPER_ADMIN)->whereNull('organization_id')->whereNull('resource_type')->whereNull('resource_id')->whereNull('revoked_at'),
            'organizationMemberships.organization',
            'teamMemberships.team.organization',
            'gameSessionParticipations.session.organization',
            'gameSessionParticipations.registration.team', 'gameSessionParticipations.registration.score',
        ]);

        return $this->userProfile($user) + ['audit' => $this->auditHistory($user)];
    }

    public function setStatus(User $actor, string $userId, array $input): array
    {
        return DB::transaction(function () use ($actor, $userId, $input): array {
            DB::statement('LOCK TABLE role_assignments IN SHARE ROW EXCLUSIVE MODE');
            $ids = User::query()->whereIn('public_id', [$actor->public_id, $userId])->orderBy('id')->lockForUpdate()->get();
            $lockedActor = $ids->firstWhere('id', $actor->id);
            $target = $ids->firstWhere('public_id', $userId);
            if (! $lockedActor || ! $target) {
                throw $this->organizations->notFound();
            }
            $lockedActor->ensureActive();
            Gate::forUser($lockedActor)->authorize('admin.users.status');
            if ($lockedActor->is($target)) {
                throw new ApiException('ACTION_NOT_ALLOWED', 'Administrators cannot change their own account status.', [], 409);
            }
            if ($target->status === UserStatus::Blocked->value && $input['status'] !== UserStatus::Active->value) {
                throw new ApiException('ACTION_NOT_ALLOWED', 'A BLOCKED account must be explicitly reactivated through the audited unblock procedure.', [], 409);
            }

            $wasActiveSuperAdmin = $target->status === UserStatus::Active->value && $this->activeGlobalRole($target->id);
            $isDeactivating = $input['status'] !== UserStatus::Active->value;
            if ($wasActiveSuperAdmin && $isDeactivating && $this->activeSuperAdminCount() <= 1) {
                throw new ApiException('ACTION_NOT_ALLOWED', 'The last active SUPER_ADMIN cannot be suspended or blocked.', [], 409);
            }

            $before = $target->status;
            if ($before !== $input['status']) {
                $target->forceFill(['status' => $input['status']])->save();
                $target->tokens()->delete();
                $action = $before === UserStatus::Blocked->value && $input['status'] === UserStatus::Active->value ? 'user.block_lifted' : 'user.status_changed';
                $this->audit->record($action, 'user', $target->public_id, $input['reason'], [
                    'before' => ['status' => $before], 'after' => ['status' => $target->status],
                ], $lockedActor->id);
            }

            return $this->userProfile($target->fresh());
        }, 3);
    }

    public function administrators(User $actor, array $input): CursorPaginator
    {
        Gate::forUser($actor)->authorize('admin.users.view');
        $query = User::query()
            ->whereHas('globalRoleAssignments', fn (Builder $roles) => $roles->where('role_code', RoleAssignment::SUPER_ADMIN)->whereNull('organization_id')->whereNull('resource_type')->whereNull('resource_id')->whereNull('revoked_at'))
            ->with(['globalRoleAssignments' => fn (HasMany $roles) => $roles->where('role_code', RoleAssignment::SUPER_ADMIN)->whereNull('organization_id')->whereNull('resource_type')->whereNull('resource_id')->whereNull('revoked_at'), 'organizationMemberships.organization']);
        $this->searchUsers($query, $input['q'] ?? null);

        return $query->orderBy('public_id')->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);
    }

    public function grantAdministrator(User $actor, string $userId, string $reason): array
    {
        $assignment = $this->globalRoles->grant($actor, $userId, $reason);

        return ['user_id' => $assignment->user->public_id, 'role' => RoleAssignment::SUPER_ADMIN, 'created_at' => $assignment->created_at?->toISOString()];
    }

    public function revokeAdministrator(User $actor, string $userId, string $reason): void
    {
        $this->globalRoles->revoke($actor, $userId, $reason);
    }

    public function players(User $actor, array $input): CursorPaginator
    {
        Gate::forUser($actor)->authorize('admin.players.view');
        $query = User::query()->where(function (Builder $players): void {
            $players->whereHas('teamMemberships')->orWhereHas('gameSessionParticipations');
        });
        $this->scopePlayers($actor, $query);
        $this->searchPlayers($actor, $query, $input['q'] ?? null);
        if (isset($input['status'])) {
            if (! $this->organizations->isSuperAdmin($actor) && ! $this->organizations->organizations($actor, true)->exists()) {
                throw new ApiException('ACTION_NOT_ALLOWED', 'GameMasters cannot filter player results by account status.', [], 403);
            }
            $query->where('status', $input['status']);
        }
        if ($this->organizations->isSuperAdmin($actor)) {
            $query->withCount(['teamMemberships as active_team_memberships_count' => fn (Builder $members) => $members->where('status', 'ACTIVE')]);
        } elseif ($this->organizations->organizations($actor, true)->exists()) {
            $organizationIds = $this->organizations->organizations($actor, true)->select('organizations.id');
            $query->withCount(['teamMemberships as active_team_memberships_count' => fn (Builder $members) => $members->where('status', 'ACTIVE')->whereHas('team', fn (Builder $teams) => $teams->whereIn('organization_id', $organizationIds))]);
        } else {
            $query->withCount(['gameSessionParticipations as active_team_memberships_count' => fn (Builder $players) => $players->whereIn('game_session_id', $this->assignedSessionIds($actor))]);
        }
        $query->orderBy('public_id');

        return $query->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);
    }

    public function listProfile(User $user): array
    {
        return $this->userProfile($user);
    }

    public function administratorProfile(User $user): array
    {
        return $this->safeUser($user) + [
            'global_roles' => $user->globalRoleAssignments->pluck('role_code')->unique()->values()->all(),
            'organization_memberships' => $user->organizationMemberships->map(fn (OrganizationMember $member): array => [
                'organization' => ['id' => $member->organization->public_id, 'name' => $member->organization->name],
                'role' => $member->role, 'status' => $member->status,
            ])->values()->all(),
        ];
    }

    public function playerListProfile(User $actor, User $user): array
    {
        return $this->playerSafeProfile($actor, $user) + ['active_team_memberships_count' => $user->active_team_memberships_count ?? 0];
    }

    public function player(User $actor, string $userId): array
    {
        Gate::forUser($actor)->authorize('admin.players.view');
        $query = User::query()->where('public_id', $userId);
        $this->scopePlayers($actor, $query);
        $user = $query->with($this->playerRelations($actor))->first();
        if (! $user) {
            throw $this->organizations->notFound();
        }

        return [
            'user' => $this->playerSafeProfile($actor, $user),
            'team_memberships' => $user->relationLoaded('teamMemberships') ? $user->teamMemberships->map(fn (TeamMember $member): array => [
                'team_id' => $member->team->public_id, 'team_name' => $member->team->name,
                'organization' => $member->team->organization ? ['id' => $member->team->organization->public_id, 'name' => $member->team->organization->name] : null,
                'status' => $member->status, 'joined_at' => $member->joined_at?->toISOString(), 'left_at' => $member->left_at?->toISOString(),
            ])->values()->all() : [],
            'participation' => $user->gameSessionParticipations->map(fn (GameSessionPlayer $player): array => [
                'player_id' => $player->public_id, 'session_id' => $player->session->public_id,
                'session_status' => $player->session->status,
                'organization' => ['id' => $player->session->organization->public_id, 'name' => $player->session->organization->name],
                'team_id' => $player->registration->team->public_id, 'team_name' => $player->registration->team->name,
                'score' => $this->organizations->isSuperAdmin($actor) || $this->organizations->organizations($actor, true)->exists()
                    ? ($player->registration->score ? ['score' => $player->registration->score->score, 'effective_elapsed_seconds' => $player->registration->score->effective_elapsed_seconds, 'hints_used' => $player->registration->score->hints_used, 'finalized' => $player->registration->score->finalized] : null) : null,
                'participation_status' => $player->participation_status, 'joined_runtime_at' => $player->joined_runtime_at?->toISOString(),
                'last_seen_at' => $player->last_seen_at?->toISOString(), 'roster_frozen_at' => $player->roster_frozen_at?->toISOString(),
            ])->values()->all(),
        ];
    }

    public function capabilities(User $actor): array
    {
        $global = $actor->globalRoleAssignments()->where('role_code', RoleAssignment::SUPER_ADMIN)->whereNull('organization_id')
            ->whereNull('resource_type')->whereNull('resource_id')->whereNull('revoked_at')->pluck('role_code')->unique()->values()->all();
        $memberships = $actor->organizationMemberships()->where('status', 'ACTIVE')->with('organization')->get();
        $isSuper = in_array(RoleAssignment::SUPER_ADMIN, $global, true);
        $capabilities = [];
        if ($isSuper) {
            $capabilities = ['admin.users.view', 'admin.users.status', 'admin.admins.manage', 'admin.players.view', 'admin.roles.view'];
        } else {
            foreach ($memberships as $membership) {
                if ($membership->role === OrganizationRole::OrganizationAdmin->value) {
                    $capabilities = array_merge($capabilities, ['organization.members.manage', 'admin.players.view']);
                }
                if ($membership->role === OrganizationRole::GameMaster->value) {
                    $capabilities[] = 'session.players.operate';
                }
            }
            if (RoleAssignment::query()->where('user_id', $actor->id)->where('role_code', 'GAMEMASTER')->whereNull('revoked_at')->exists()) {
                $capabilities[] = 'admin.players.view';
            }
        }

        return ['global_roles' => $global, 'organizations' => $memberships->map(fn (OrganizationMember $membership): array => [
            'id' => $membership->organization->public_id, 'name' => $membership->organization->name, 'role' => $membership->role,
        ])->values()->all(), 'capabilities' => array_values(array_unique($capabilities))];
    }

    public function roleOverview(User $actor): array
    {
        Gate::forUser($actor)->authorize('admin.roles.view');
        $globalCount = RoleAssignment::query()->where('role_code', RoleAssignment::SUPER_ADMIN)->whereNull('organization_id')->whereNull('resource_type')->whereNull('resource_id')->whereNull('revoked_at')
            ->whereHas('user', fn (Builder $users) => $users->where('status', UserStatus::Active->value))->count();
        $orgCounts = OrganizationMember::query()->where('status', 'ACTIVE')->select('role')->selectRaw('count(*) as assignments_count')->groupBy('role')->pluck('assignments_count', 'role');
        $gameMasterAssignments = RoleAssignment::query()->where('role_code', 'GAMEMASTER')->whereNull('revoked_at')->count();
        $audit = DB::table('audit_events')->leftJoin('users as actors', 'actors.id', '=', 'audit_events.actor_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'audit_events.organization_id')
            ->whereIn('audit_events.action', ['role_assignment.granted', 'role_assignment.revoked', 'organization.member_added.v1', 'organization.member_role_changed.v1', 'organization.member_removed.v1'])
            ->orderByDesc('audit_events.occurred_at')->limit(50)
            ->select(['actors.public_id as actor_id', 'audit_events.action', 'audit_events.resource_type', 'audit_events.resource_id', 'organizations.public_id as organization_id', 'audit_events.reason', 'audit_events.occurred_at'])
            ->selectRaw("COALESCE(audit_events.context->>'user_id', CASE WHEN audit_events.resource_type = 'user' THEN audit_events.resource_id END) as target_id")
            ->get();

        return [
            'roles' => [
                ['code' => RoleAssignment::SUPER_ADMIN, 'scope' => 'GLOBAL', 'description' => 'مدیر سراسری سامانه', 'assignments_count' => $globalCount],
                ['code' => OrganizationRole::OrganizationAdmin->value, 'scope' => 'ORGANIZATION', 'description' => 'مدیر سازمان در محدوده عضویت فعال', 'assignments_count' => $orgCounts[OrganizationRole::OrganizationAdmin->value] ?? 0],
                ['code' => OrganizationRole::GameMaster->value, 'scope' => 'ORGANIZATION/SESSION', 'description' => 'اپراتور نشست‌های تخصیص‌یافته', 'assignments_count' => $orgCounts[OrganizationRole::GameMaster->value] ?? 0, 'resource_assignments_count' => $gameMasterAssignments],
                ['code' => OrganizationRole::Staff->value, 'scope' => 'ORGANIZATION', 'description' => 'کارمند سازمان', 'assignments_count' => $orgCounts[OrganizationRole::Staff->value] ?? 0],
            ],
            'audit' => $audit->map(fn (object $event): array => [
                'actor_id' => $event->actor_id, 'action' => $event->action, 'resource_type' => $event->resource_type,
                'resource_id' => $event->resource_id, 'target_id' => $event->target_id, 'organization_id' => $event->organization_id,
                'reason' => $event->reason, 'occurred_at' => $event->occurred_at,
            ])->all(),
        ];
    }

    private function scopePlayers(User $actor, Builder $query): void
    {
        if ($this->organizations->isSuperAdmin($actor)) {
            return;
        }
        $organizationIds = $this->organizations->organizations($actor, true)->select('organizations.id');
        if ($organizationIds->exists()) {
            $query->where(function (Builder $scoped) use ($organizationIds): void {
                $scoped->whereHas('teamMemberships.team', fn (Builder $teams) => $teams->whereIn('organization_id', $organizationIds))
                    ->orWhereHas('gameSessionParticipations.session', fn (Builder $sessions) => $sessions->whereIn('organization_id', $organizationIds));
            });

            return;
        }

        $query->whereHas('gameSessionParticipations', fn (Builder $players) => $players->whereIn('game_session_id', $this->assignedSessionIds($actor)));
    }

    private function searchUsers(Builder $query, ?string $term): void
    {
        if (! is_string($term) || trim($term) === '') {
            return;
        }
        $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)).'%';
        $query->where(fn (Builder $search) => $search->whereRaw('lower(name) LIKE lower(?)', [$needle])
            ->orWhereRaw('lower(email) LIKE lower(?)', [$needle])->orWhere('mobile', 'LIKE', $needle));
    }

    private function searchPlayers(User $actor, Builder $query, ?string $term): void
    {
        if ($this->organizations->isSuperAdmin($actor) || $this->organizations->organizations($actor, true)->exists()) {
            $this->searchUsers($query, $term);

            return;
        }
        if (! is_string($term) || trim($term) === '') {
            return;
        }
        $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)).'%';
        $query->where(fn (Builder $search) => $search->whereRaw('lower(name) LIKE lower(?)', [$needle])->orWhere('public_id', 'LIKE', $needle));
    }

    private function userProfile(User $user): array
    {
        return $this->safeUser($user) + [
            'global_roles' => $user->globalRoleAssignments->pluck('role_code')->unique()->values()->all(),
            'organization_memberships' => $user->organizationMemberships->map(fn (OrganizationMember $member): array => [
                'organization' => ['id' => $member->organization->public_id, 'name' => $member->organization->name],
                'role' => $member->role, 'status' => $member->status, 'joined_at' => $member->joined_at?->toISOString(), 'left_at' => $member->left_at?->toISOString(),
            ])->values()->all(),
            'team_memberships' => $user->teamMemberships->map(fn (TeamMember $member): array => [
                'team_id' => $member->team->public_id, 'team_name' => $member->team->name, 'status' => $member->status,
                'joined_at' => $member->joined_at?->toISOString(), 'left_at' => $member->left_at?->toISOString(),
            ])->values()->all(),
            'participation' => $user->gameSessionParticipations->map(fn (GameSessionPlayer $player): array => [
                'session_id' => $player->session->public_id, 'session_status' => $player->session->status,
                'team_id' => $player->registration->team->public_id, 'team_name' => $player->registration->team->name,
                'participation_status' => $player->participation_status,
            ])->values()->all(),
        ];
    }

    private function safeUser(User $user): array
    {
        return ['id' => $user->public_id, 'name' => $user->name, 'email' => $user->email, 'mobile' => $user->mobile,
            'status' => $user->status, 'last_login_at' => $user->last_login_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(), 'updated_at' => $user->updated_at?->toISOString()];
    }

    private function auditHistory(User $user): array
    {
        $query = DB::table('audit_events')->leftJoin('users as actors', 'actors.id', '=', 'audit_events.actor_id')
            ->leftJoin('organizations as organizations', 'organizations.id', '=', 'audit_events.organization_id')
            ->where(fn ($events) => $events->where(fn ($userEvents) => $userEvents->where('resource_type', 'user')->where('resource_id', $user->public_id))
                ->orWhere(fn ($membershipEvents) => $membershipEvents->whereIn('action', ['organization.member_added.v1', 'organization.member_role_changed.v1', 'organization.member_removed.v1'])
                    ->whereRaw("context->>'user_id' = ?", [$user->public_id])))
            ->select(['audit_events.actor_type', 'actors.public_id as actor_public_id', 'audit_events.action', 'audit_events.reason', 'audit_events.context', 'organizations.public_id as organization_public_id', 'audit_events.occurred_at'])
            ->orderByDesc('occurred_at')->limit(50);

        return $query->get()->map(fn (object $event): array => [
            'actor_type' => $event->actor_type, 'actor_id' => $event->actor_public_id,
            'action' => $event->action, 'reason' => $event->reason,
            'context' => $this->sanitizeAuditContext(is_array($event->context) ? $event->context : json_decode((string) $event->context, true)),
            'organization_id' => $event->organization_public_id, 'occurred_at' => $event->occurred_at,
        ])->all();
    }

    private function playerRelations(User $actor): array
    {
        if ($this->organizations->isSuperAdmin($actor)) {
            return ['organizationMemberships.organization', 'teamMemberships.team.organization', 'gameSessionParticipations.session.organization', 'gameSessionParticipations.registration.team', 'gameSessionParticipations.registration.score'];
        }
        $organizationIds = $this->organizations->organizations($actor, true)->select('organizations.id');
        if ($organizationIds->exists()) {
            return [
                'teamMemberships' => fn (HasMany $members) => $members->whereHas('team', fn (Builder $teams) => $teams->whereIn('organization_id', $organizationIds)),
                'teamMemberships.team.organization',
                'gameSessionParticipations' => fn (HasMany $players) => $players->whereHas('session', fn (Builder $sessions) => $sessions->whereIn('organization_id', $organizationIds)),
                'gameSessionParticipations.session.organization', 'gameSessionParticipations.registration.team', 'gameSessionParticipations.registration.score',
            ];
        }
        $sessions = $this->assignedSessionIds($actor);

        return [
            'gameSessionParticipations' => fn (HasMany $players) => $players->whereIn('game_session_id', $sessions),
            'gameSessionParticipations.session.organization', 'gameSessionParticipations.registration.team', 'gameSessionParticipations.registration.score',
        ];
    }

    private function playerSafeProfile(User $actor, User $user): array
    {
        if ($this->organizations->isSuperAdmin($actor)) {
            return $this->safeUser($user);
        }
        if ($this->organizations->organizations($actor, true)->exists()) {
            return ['id' => $user->public_id, 'name' => $user->name, 'status' => $user->status];
        }

        return ['id' => $user->public_id, 'name' => $user->name];
    }

    private function assignedSessionIds(User $actor): \Illuminate\Database\Query\Builder
    {
        return DB::table('role_assignments as assignments')
            ->leftJoin('game_sessions as assigned_sessions', fn ($join) => $join->on('assignments.resource_id', '=', DB::raw('assigned_sessions.public_id::text'))->on('assignments.organization_id', '=', 'assigned_sessions.organization_id')->where('assignments.resource_type', '=', 'game_session'))
            ->leftJoin('games as assigned_games', fn ($join) => $join->on('assignments.resource_id', '=', DB::raw('assigned_games.public_id::text'))->on('assignments.organization_id', '=', 'assigned_games.organization_id')->where('assignments.resource_type', '=', 'game'))
            ->leftJoin('game_sessions as game_sessions', 'game_sessions.game_id', '=', 'assigned_games.id')
            ->where('assignments.user_id', $actor->id)->where('assignments.role_code', 'GAMEMASTER')->whereNull('assignments.revoked_at')
            ->selectRaw('COALESCE(assigned_sessions.id, game_sessions.id)');
    }

    private function findUser(string $userId): User
    {
        return Str::isUuid($userId) ? User::query()->where('public_id', $userId)->first() ?? throw $this->organizations->notFound() : throw $this->organizations->notFound();
    }

    private function activeGlobalRole(int $userId): bool
    {
        return RoleAssignment::query()->where('user_id', $userId)->where('role_code', RoleAssignment::SUPER_ADMIN)->whereNull('organization_id')
            ->whereNull('resource_type')->whereNull('resource_id')->whereNull('revoked_at')->exists();
    }

    private function activeSuperAdminCount(): int
    {
        return RoleAssignment::query()->where('role_code', RoleAssignment::SUPER_ADMIN)->whereNull('revoked_at')
            ->whereNull('organization_id')->whereNull('resource_type')->whereNull('resource_id')
            ->whereHas('user', fn (Builder $users) => $users->where('status', UserStatus::Active->value))->count();
    }

    private function sanitizeAuditContext(mixed $context): array
    {
        if (! is_array($context)) {
            return [];
        }
        $safe = [];
        foreach ($context as $key => $value) {
            if (preg_match('/password|token|secret|private|credential|device/i', (string) $key)) {
                continue;
            }
            $safe[$key] = is_array($value) ? $this->sanitizeAuditContext($value) : $value;
        }

        return $safe;
    }
}

<?php

namespace App\Services\Organizations;

use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationStatus;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationSetting;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class OrganizationService
{
    public function __construct(private OrganizationScope $scope, private OrganizationEvents $events) {}

    public function listing(User $actor, array $input): CursorPaginator
    {
        Gate::forUser($actor)->authorize('viewAny', Organization::class);
        $input = OrganizationInput::validate($input, 'index');

        return $this->scope->organizations($actor, true)->orderBy('public_id')->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);
    }

    public function show(User $actor, string $organizationId): array
    {
        $context = $this->scope->resolve($actor, $organizationId);
        $context->authorize('view');

        return $context->organization->publicProfile(! Gate::forUser($actor)->allows('update', $context->organization));
    }

    public function members(User $actor, string $organizationId, array $input): CursorPaginator
    {
        $context = $this->scope->resolve($actor, $organizationId);
        $context->authorize('manageMembers');
        $input = OrganizationInput::validate($input, 'members');

        return $context->scope(OrganizationMember::query())->where('organization_members.status', OrganizationMemberStatus::Active->value)
            ->join('users', 'users.id', '=', 'organization_members.user_id')->select('organization_members.*', 'users.public_id as cursor_user_id')
            ->with('user')->orderBy('cursor_user_id')->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);
    }

    public function create(User $actor, array $input): Organization
    {
        return $this->write(function () use ($actor, $input): Organization {
            $actor = $this->lockActor($actor);
            Gate::forUser($actor)->authorize('create', Organization::class);
            $input = OrganizationInput::validate($input, 'create');
            $organization = Organization::create(array_intersect_key($input, array_flip(['name', 'slug', 'timezone_default'])));
            $settings = new OrganizationSetting;
            $settings->forceFill(['organization_id' => $organization->id])->save();
            $this->events->record('organization.created.v1', $actor, $organization, ['after' => $organization->publicProfile()], $input['reason'] ?? null);

            return $organization;
        });
    }

    public function update(User $actor, string $organizationId, array $input): Organization
    {
        return $this->write(function () use ($actor, $organizationId, $input): Organization {
            $context = $this->mutationContext($actor, $organizationId, 'update');
            $input = OrganizationInput::validate($input, 'update');
            $organization = $context->organization;
            $before = $organization->publicProfile();
            $organization->fill(array_intersect_key($input, array_flip(['name', 'slug', 'timezone_default'])));
            if ($organization->isDirty()) {
                $organization->save();
                $this->events->record('organization.updated.v1', $context->actor, $organization, ['before' => $before, 'after' => $organization->publicProfile()], $input['reason'] ?? null);
            }

            return $organization;
        });
    }

    public function addMember(User $actor, string $organizationId, array $input): OrganizationMember
    {
        return $this->write(function () use ($actor, $organizationId, $input): OrganizationMember {
            $context = $this->mutationContext($actor, $organizationId, 'manageMembers');
            $input = OrganizationInput::validate($input, 'addMember');
            $user = User::query()->where('public_id', $input['user_id'])->first();
            if (! $user) {
                throw $this->scope->notFound();
            }
            if ($context->organization->members()->where('user_id', $user->id)->where('status', OrganizationMemberStatus::Active->value)->exists()) {
                throw new ApiException('CONFLICT', 'An active membership already exists.', [], 409);
            }
            $member = new OrganizationMember;
            $member->forceFill(['organization_id' => $context->organization->id, 'user_id' => $user->id, 'role' => $input['role'], 'status' => OrganizationMemberStatus::Active->value, 'joined_at' => now()])->save();
            $member->setRelation('user', $user);
            $this->events->record('organization.member_added.v1', $context->actor, $context->organization, ['user_id' => $user->public_id, 'role' => $member->role, 'after' => $member->publicProfile()], $input['reason'] ?? null);

            return $member;
        });
    }

    public function changeRole(User $actor, string $organizationId, string $userId, array $input): OrganizationMember
    {
        return $this->write(function () use ($actor, $organizationId, $userId, $input): OrganizationMember {
            $context = $this->mutationContext($actor, $organizationId, 'manageMembers');
            $input = OrganizationInput::validate($input, 'changeRole');
            $member = $this->activeMember($context, $userId);
            if ($member->role !== $input['role']) {
                $oldRole = $member->role;
                $member->forceFill(['role' => $input['role']])->save();
                $this->events->record('organization.member_role_changed.v1', $context->actor, $context->organization, ['user_id' => $member->user->public_id, 'old_role' => $oldRole, 'new_role' => $member->role], $input['reason']);
            }

            return $member;
        });
    }

    public function removeMember(User $actor, string $organizationId, string $userId, array $input = []): void
    {
        $this->write(function () use ($actor, $organizationId, $userId, $input): void {
            $context = $this->mutationContext($actor, $organizationId, 'manageMembers');
            $input = OrganizationInput::validate($input, 'removeMember');
            $member = $this->activeMember($context, $userId);
            $before = $member->publicProfile();
            $member->forceFill(['status' => OrganizationMemberStatus::Removed->value, 'left_at' => now()])->save();
            $this->events->record('organization.member_removed.v1', $context->actor, $context->organization, ['user_id' => $member->user->public_id, 'role' => $member->role, 'before' => $before, 'after' => $member->publicProfile()], $input['reason'] ?? null);
        });
    }

    public function archive(User $actor, string $organizationId, string $reason): Organization
    {
        return $this->write(function () use ($actor, $organizationId, $reason): Organization {
            $context = $this->mutationContext($actor, $organizationId, 'archive');
            OrganizationInput::validate(['reason' => $reason], 'archive');
            $before = $context->organization->publicProfile();
            $context->organization->forceFill(['status' => OrganizationStatus::Archived->value, 'archived_at' => now()])->save();
            $this->events->record('organization.archived.v1', $context->actor, $context->organization, ['before' => $before, 'after' => $context->organization->publicProfile()], $reason);

            return $context->organization;
        });
    }

    private function mutationContext(User $actor, string $organizationId, string $ability): OrganizationContext
    {
        $context = $this->scope->resolve($this->lockActor($actor), $organizationId, true);
        $context->authorize($ability);
        if ($context->organization->status !== OrganizationStatus::Active->value) {
            throw new ApiException('STATE_TRANSITION_INVALID', 'The organization is archived.', [], 409);
        }

        return $context;
    }

    private function lockActor(User $actor): User
    {
        $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $actor->ensureActive();

        return $actor;
    }

    private function activeMember(OrganizationContext $context, string $userId): OrganizationMember
    {
        if (! Str::isUuid($userId)) {
            throw $this->scope->notFound();
        }
        $member = $context->organization->members()->where('status', OrganizationMemberStatus::Active->value)
            ->whereHas('user', fn ($users) => $users->where('public_id', $userId))->with('user')->lockForUpdate()->first();
        if (! $member) {
            throw $this->scope->notFound();
        }

        return $member;
    }

    private function write(Closure $operation): mixed
    {
        try {
            return DB::transaction($operation, 3);
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('CONFLICT', 'The organization slug or active membership already exists.', [], 409);
        }
    }
}

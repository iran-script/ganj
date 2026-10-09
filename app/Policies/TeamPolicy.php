<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Organizations\OrganizationScope;
use App\Services\Teams\TeamScope;

class TeamPolicy
{
    public function __construct(private OrganizationScope $organizations, private TeamScope $scope) {}

    public function create(User $actor): bool
    {
        return User::query()->whereKey($actor->id)->where('status', 'ACTIVE')->exists();
    }

    public function view(User $actor, Team $team): bool
    {
        return $this->scope->teams($actor)->whereKey($team->id)->exists();
    }

    public function captain(User $actor, Team $team): bool
    {
        return $this->view($actor, $team) && $team->captain_user_id === $actor->id
            && $team->activeMembers()->where('user_id', $actor->id)->exists();
    }

    public function support(User $actor, Team $team): bool
    {
        return $this->view($actor, $team) && ($this->organizations->isSuperAdmin($actor)
            || ($team->organization_id !== null && $this->organizations->membership($actor, $team->organization)?->role === OrganizationRole::OrganizationAdmin->value));
    }

    public function update(User $actor, Team $team): bool
    {
        return $this->captain($actor, $team) || ($team->organization_id !== null && $this->support($actor, $team));
    }

    public function invites(User $actor, Team $team): bool
    {
        return $this->captain($actor, $team) || $this->support($actor, $team);
    }

    public function transfer(User $actor, Team $team): bool
    {
        return $this->invites($actor, $team);
    }

    public function archive(User $actor, Team $team): bool
    {
        return $this->invites($actor, $team);
    }

    public function remove(User $actor, Team $team): bool
    {
        return $this->captain($actor, $team);
    }

    public function leave(User $actor, Team $team): bool
    {
        return $this->view($actor, $team) && $team->activeMembers()->where('user_id', $actor->id)->exists();
    }
}

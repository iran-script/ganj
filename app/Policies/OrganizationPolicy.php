<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrganizationScope;
use Illuminate\Auth\Access\Response;

class OrganizationPolicy
{
    public function __construct(private OrganizationScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $this->active($user) && ($this->scope->isSuperAdmin($user) || $this->scope->organizations($user, true)->exists());
    }

    public function create(User $user): bool
    {
        return $this->scope->isSuperAdmin($user);
    }

    public function view(User $user, Organization $organization): Response
    {
        $role = $this->scope->membership($user, $organization)?->role;

        return $this->active($user) && ($this->scope->isSuperAdmin($user) || in_array($role, [OrganizationRole::OrganizationAdmin->value, OrganizationRole::GameMaster->value], true))
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->active($user) && ($this->scope->isSuperAdmin($user) || $this->scope->membership($user, $organization)?->role === OrganizationRole::OrganizationAdmin->value);
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->update($user, $organization);
    }

    public function archive(User $user, Organization $organization): bool
    {
        return $this->scope->isSuperAdmin($user);
    }

    private function active(User $user): bool
    {
        return User::query()->whereKey($user->id)->where('status', UserStatus::Active->value)->exists();
    }
}

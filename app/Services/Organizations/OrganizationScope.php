<?php

namespace App\Services\Organizations;

use App\Enums\OrganizationMemberStatus;
use App\Enums\OrganizationRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class OrganizationScope
{
    public function isSuperAdmin(User $actor): bool
    {
        return User::query()->whereKey($actor->id)->where('status', UserStatus::Active->value)->exists()
            && RoleAssignment::query()->where('user_id', $actor->id)->where('role_code', RoleAssignment::SUPER_ADMIN)
                ->whereNull('organization_id')->whereNull('resource_type')->whereNull('resource_id')->whereNull('revoked_at')->exists();
    }

    public function canCreateOrganization(User $actor): bool
    {
        return $this->isSuperAdmin($actor) || (User::whereKey($actor->id)->where('status', UserStatus::Active->value)->exists()
            && RoleAssignment::where('user_id', $actor->id)->where('role_code', 'ORGANIZATION_OPERATOR')
                ->whereNull('organization_id')->whereNull('resource_type')->whereNull('resource_id')->whereNull('revoked_at')->exists());
    }

    public function membership(User $actor, Organization $organization): ?OrganizationMember
    {
        return $organization->members()->where('user_id', $actor->id)->where('status', OrganizationMemberStatus::Active->value)->first();
    }

    /** @return Builder<Organization> */
    public function organizations(User $actor, bool $administrationOnly = false): Builder
    {
        return Organization::query()->withGlobalScope('organization_access', function (Builder $query) use ($actor, $administrationOnly): void {
            $currentActor = User::query()->whereKey($actor->id)->firstOrFail();
            $currentActor->ensureActive();
            if (! $this->isSuperAdmin($currentActor)) {
                $query->whereHas('members', function (Builder $members) use ($currentActor, $administrationOnly): void {
                    $members->where('user_id', $currentActor->id)->where('status', OrganizationMemberStatus::Active->value)
                        ->whereIn('role', $administrationOnly ? [OrganizationRole::OrganizationAdmin->value] : [OrganizationRole::OrganizationAdmin->value, OrganizationRole::GameMaster->value]);
                });
            }
        });
    }

    public function resolve(User $actor, string $publicId, bool $lock = false): OrganizationContext
    {
        if (! Str::isUuid($publicId)) {
            throw $this->notFound();
        }
        $query = $this->organizations($actor)->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $organization = $query->first();
        if (! $organization) {
            throw $this->notFound();
        }

        return new OrganizationContext($actor, $organization, $this->membership($actor, $organization));
    }

    public function notFound(): ApiException
    {
        return new ApiException('RESOURCE_NOT_FOUND', 'The requested resource was not found.', [], 404);
    }
}

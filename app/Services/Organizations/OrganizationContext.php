<?php

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final readonly class OrganizationContext
{
    public function __construct(public User $actor, public Organization $organization, public ?OrganizationMember $membership) {}

    public function authorize(string $ability): void
    {
        Gate::forUser($this->actor)->authorize($ability, $this->organization);
    }

    /**
     * The query must represent a tenant-owned model with organization_id.
     * Authorization is rechecked so a retained context never caches permission.
     */
    public function scope(Builder $query): Builder
    {
        return $query->withGlobalScope('organization_context', function (Builder $builder): void {
            $this->authorize('view');
            $builder->where($builder->getModel()->qualifyColumn('organization_id'), $this->organization->id);
        });
    }
}

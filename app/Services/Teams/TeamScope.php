<?php

namespace App\Services\Teams;

use App\Models\Team;
use App\Models\User;
use App\Services\Organizations\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class TeamScope
{
    public function __construct(private OrganizationScope $organizations) {}

    /** @return Builder<Team> */
    public function teams(User $actor): Builder
    {
        return Team::query()->withGlobalScope('team_access', function (Builder $query) use ($actor): void {
            User::query()->findOrFail($actor->id)->ensureActive();
            if ($this->organizations->isSuperAdmin($actor)) {
                return;
            }
            $allowedOrganizations = $this->organizations->organizations($actor)->select('organizations.id');
            $adminOrganizations = $this->organizations->organizations($actor, true)->select('organizations.id');
            $query->where(function (Builder $tenant) use ($allowedOrganizations): void {
                $tenant->whereNull('teams.organization_id')->orWhereIn('teams.organization_id', $allowedOrganizations);
            })->where(function (Builder $access) use ($actor, $adminOrganizations): void {
                $access->whereHas('members', function (Builder $members) use ($actor): void {
                    $members->where('user_id', $actor->id)->where(function (Builder $period): void {
                        $period->where('status', 'ACTIVE')->orWhere('teams.status', 'ARCHIVED');
                    });
                })->orWhereIn('teams.organization_id', $adminOrganizations);
            });
        });
    }

    public function resolve(User $actor, string $publicId): Team
    {
        $team = Str::isUuid($publicId) ? $this->teams($actor)->where('public_id', $publicId)->first() : null;
        if (! $team) {
            throw $this->organizations->notFound();
        }

        return $team;
    }

    public function lock(User $actor, Team $team): Team
    {
        if ($team->organization_id !== null) {
            $context = $this->organizations->resolve($actor, $team->organization->public_id, true);
            $context->scope(Team::query())->whereKey($team->id)->firstOrFail();
        }
        $locked = $this->teams($actor)->whereKey($team->id)->lockForUpdate()->first();
        if (! $locked) {
            throw $this->organizations->notFound();
        }

        return $locked;
    }
}

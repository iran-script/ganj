<?php

namespace App\Services\Scenarios;

use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Organizations\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ScenarioScope
{
    public function __construct(private OrganizationScope $organizations) {}

    public function scenarios(User $actor): Builder
    {
        return Scenario::query()->withGlobalScope('scenario_access', function (Builder $query) use ($actor): void {
            $query->whereIn('organization_id', $this->organizations->organizations($actor, true)->select('id'));
        });
    }

    public function resolve(User $actor, string $id, bool $lock = false): Scenario
    {
        if (! Str::isUuid($id)) {
            throw $this->organizations->notFound();
        }
        $scenario = $this->scenarios($actor)->where('public_id', $id)->first();
        if (! $scenario) {
            throw $this->organizations->notFound();
        }
        if ($lock) {
            $context = $this->organizations->resolve($actor, $scenario->organization->public_id, true);
            $context->authorize('update');
            $scenario = $context->scope($this->scenarios($actor))->whereKey($scenario->id)->lockForUpdate()->first();
            if (! $scenario) {
                throw $this->organizations->notFound();
            }
        }

        return $scenario;
    }

    public function version(User $actor, string $id, bool $lock = false): ScenarioVersion
    {
        if (! Str::isUuid($id)) {
            throw $this->organizations->notFound();
        }
        $version = ScenarioVersion::where('public_id', $id)->whereIn('scenario_id', $this->scenarios($actor)->select('id'))->first();
        if (! $version) {
            throw $this->organizations->notFound();
        }
        $scenario = $this->resolve($actor, $version->scenario->public_id, $lock);
        if ($lock) {
            $version = ScenarioVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
        }

        return $version->setRelation('scenario', $scenario);
    }

    public function administrator(User $actor, Scenario $scenario): bool
    {
        return $this->organizations->organizations($actor, true)->whereKey($scenario->organization_id)->exists();
    }
}

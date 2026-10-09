<?php

namespace App\Policies;

use App\Models\Scenario;
use App\Models\User;
use App\Services\Scenarios\ScenarioScope;

class ScenarioPolicy
{
    public function __construct(private ScenarioScope $scope) {}

    public function view(User $actor, Scenario $scenario): bool
    {
        return $this->scope->administrator($actor, $scenario);
    }

    public function manage(User $actor, Scenario $scenario): bool
    {
        return $this->scope->administrator($actor, $scenario);
    }

    public function publish(User $actor, Scenario $scenario): bool
    {
        return $this->scope->administrator($actor, $scenario);
    }
}

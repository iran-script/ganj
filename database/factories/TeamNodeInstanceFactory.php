<?php

namespace Database\Factories;

use App\Models\ScenarioNode;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeamNodeInstance> */
class TeamNodeInstanceFactory extends Factory
{
    public function definition(): array
    {
        return ['team_scenario_instance_id' => TeamScenarioInstance::factory(),
            'scenario_node_id' => fn (array $a): int => ScenarioNode::where('scenario_version_id', TeamScenarioInstance::findOrFail($a['team_scenario_instance_id'])->scenario_version_id)->orderBy('id')->firstOrFail()->id,
            'status' => 'LOCKED', 'attempt_count' => 0, 'version_no' => 1, 'state_json' => []];
    }
}

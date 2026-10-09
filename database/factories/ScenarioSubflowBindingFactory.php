<?php

namespace Database\Factories;

use App\Models\ScenarioNode;
use App\Models\ScenarioSubflow;
use App\Models\ScenarioSubflowBinding;
use App\Models\ScenarioSubflowVersion;
use App\Models\ScenarioVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioSubflowBinding> */
class ScenarioSubflowBindingFactory extends Factory
{
    public function definition(): array
    {
        return ['scenario_version_id' => ScenarioVersion::factory(), 'node_id' => fn (array $a): int => ScenarioNode::factory()->create(['scenario_version_id' => $a['scenario_version_id'], 'node_type' => 'Subflow'])->id, 'subflow_version_id' => function (array $a): int {
            $scenario = ScenarioVersion::findOrFail($a['scenario_version_id'])->scenario;
            $subflow = ScenarioSubflow::factory()->create(['organization_id' => $scenario->organization_id]);

            return ScenarioSubflowVersion::factory()->create(['scenario_subflow_id' => $subflow->id, 'status' => 'PUBLISHED', 'published_at' => now()])->id;
        }];
    }
}

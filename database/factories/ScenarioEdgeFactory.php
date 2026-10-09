<?php

namespace Database\Factories;

use App\Models\ScenarioEdge;
use App\Models\ScenarioNode;
use App\Models\ScenarioVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioEdge> */
class ScenarioEdgeFactory extends Factory
{
    public function definition(): array
    {
        return ['scenario_version_id' => ScenarioVersion::factory(), 'edge_key' => 'edge_'.fake()->unique()->numberBetween(1, 9999999), 'from_node_id' => fn (array $a): int => ScenarioNode::factory()->create(['scenario_version_id' => $a['scenario_version_id']])->id, 'to_node_id' => fn (array $a): int => ScenarioNode::factory()->create(['scenario_version_id' => $a['scenario_version_id'], 'node_type' => 'Finish'])->id, 'priority' => 0, 'metadata' => []];
    }
}

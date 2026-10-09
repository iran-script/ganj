<?php

namespace Database\Factories;

use App\Models\ScenarioSubflow;
use App\Models\ScenarioSubflowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioSubflowVersion> */
class ScenarioSubflowVersionFactory extends Factory
{
    public function definition(): array
    {
        return ['scenario_subflow_id' => ScenarioSubflow::factory(), 'version_no' => 1, 'status' => 'DRAFT', 'graph_json' => ['nodes' => [['node_key' => 'start', 'node_type' => 'Start', 'config' => []], ['node_key' => 'finish', 'node_type' => 'Finish', 'config' => []]], 'edges' => [['edge_key' => 'go', 'from_node' => 'start', 'to_node' => 'finish', 'priority' => 0]]]];
    }
}

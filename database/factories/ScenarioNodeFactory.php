<?php

namespace Database\Factories;

use App\Models\ScenarioNode;
use App\Models\ScenarioVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioNode> */
class ScenarioNodeFactory extends Factory
{
    public function definition(): array
    {
        return ['scenario_version_id' => ScenarioVersion::factory(), 'node_key' => 'node_'.fake()->unique()->numberBetween(1, 9999999), 'node_type' => 'Start', 'config' => []];
    }
}

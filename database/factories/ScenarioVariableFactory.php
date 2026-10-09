<?php

namespace Database\Factories;

use App\Models\ScenarioVariable;
use App\Models\ScenarioVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioVariable> */
class ScenarioVariableFactory extends Factory
{
    public function definition(): array
    {
        return ['scenario_version_id' => ScenarioVersion::factory(), 'key' => 'attempts', 'type' => 'INTEGER', 'scope' => 'TEAM', 'default_value' => 0, 'writable' => true, 'created_at' => now()];
    }
}

<?php

namespace Database\Factories;

use App\Models\ScenarioSimulationRun;
use App\Models\ScenarioVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioSimulationRun> */
class ScenarioSimulationRunFactory extends Factory
{
    public function definition(): array
    {
        return ['scenario_version_id' => ScenarioVersion::factory(), 'input_config' => [], 'result' => ['mode' => 'STRUCTURAL_ONLY', 'runtime_executed' => false], 'created_by' => User::factory(), 'created_at' => now()];
    }
}

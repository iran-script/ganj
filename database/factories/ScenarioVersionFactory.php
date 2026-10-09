<?php

namespace Database\Factories;

use App\Models\Scenario;
use App\Models\ScenarioVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioVersion> */
class ScenarioVersionFactory extends Factory
{
    public function definition(): array
    {
        return ['scenario_id' => Scenario::factory(), 'version_no' => 1, 'status' => 'DRAFT', 'challenge_mix' => []];
    }
}

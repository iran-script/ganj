<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ScenarioSubflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioSubflow> */
class ScenarioSubflowFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'name' => fake()->sentence(3), 'status' => 'DRAFT'];
    }
}

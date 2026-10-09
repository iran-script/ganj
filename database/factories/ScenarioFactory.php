<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Scenario> */
class ScenarioFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'title' => fake()->sentence(3), 'status' => 'DRAFT', 'created_by' => User::factory()];
    }
}

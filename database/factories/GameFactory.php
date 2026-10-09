<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class GameFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'name' => fake()->words(3, true), 'slug' => fake()->unique()->slug(), 'description' => null, 'status' => 'ACTIVE', 'default_timezone' => 'UTC'];
    }
}

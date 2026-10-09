<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'slug' => fake()->unique()->slug(), 'timezone_default' => 'UTC'];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'ARCHIVED', 'archived_at' => now()]);
    }
}

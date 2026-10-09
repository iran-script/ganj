<?php

namespace Database\Factories;

use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RoleAssignment> */
class RoleAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'role_code' => RoleAssignment::SUPER_ADMIN, 'organization_id' => null, 'resource_type' => null, 'resource_id' => null, 'created_at' => now()];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => ['revoked_at' => now()]);
    }
}

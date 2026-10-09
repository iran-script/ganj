<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationMember> */
class OrganizationMemberFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'user_id' => User::factory(), 'role' => 'STAFF', 'status' => 'ACTIVE', 'joined_at' => now()];
    }

    public function administrator(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => 'ORGANIZATION_ADMIN']);
    }

    public function gameMaster(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => 'GAMEMASTER']);
    }

    public function removed(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'REMOVED', 'left_at' => now()]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'SUSPENDED']);
    }
}

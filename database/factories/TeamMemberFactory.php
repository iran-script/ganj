<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeamMember> */
class TeamMemberFactory extends Factory
{
    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'user_id' => User::factory(), 'status' => 'ACTIVE', 'joined_at' => now(), 'created_at' => now()];
    }

    public function left(): static
    {
        return $this->state(fn (): array => ['status' => 'LEFT', 'left_at' => now()]);
    }

    public function removed(): static
    {
        return $this->state(fn (): array => ['status' => 'REMOVED', 'left_at' => now(), 'removed_by' => User::factory()]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeamInvite> */
class TeamInviteFactory extends Factory
{
    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'code_hash' => hash('sha256', bin2hex(random_bytes(32))), 'created_by' => User::factory(), 'uses_count' => 0];
    }

    public function code(string $code): static
    {
        return $this->state(fn (): array => ['code_hash' => hash('sha256', $code)]);
    }
}

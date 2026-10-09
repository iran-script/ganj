<?php

namespace Database\Factories;

use App\Models\GameSession;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class GameSessionTeamFactory extends Factory
{
    public function definition(): array
    {
        return ['game_session_id' => GameSession::factory(), 'team_id' => Team::factory(), 'status' => 'REGISTERED', 'registered_at' => now()];
    }
}

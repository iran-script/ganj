<?php

namespace Database\Factories;

use App\Models\GameSessionTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GameSessionPlayerFactory extends Factory
{
    public function definition(): array
    {
        return ['game_session_team_id' => GameSessionTeam::factory(), 'game_session_id' => fn (array $a): int => GameSessionTeam::findOrFail($a['game_session_team_id'])->game_session_id, 'user_id' => User::factory(), 'participation_status' => 'JOINED'];
    }
}

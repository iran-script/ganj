<?php

namespace Database\Factories;

use App\Models\GameSessionTeam;
use Illuminate\Database\Eloquent\Factories\Factory;

class GameSessionRosterSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return ['game_session_team_id' => GameSessionTeam::factory(), 'version' => 1, 'snapshot_json' => ['version' => 1], 'frozen_at' => now(), 'created_at' => now()];
    }
}

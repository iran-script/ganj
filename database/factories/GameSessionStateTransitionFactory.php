<?php

namespace Database\Factories;

use App\Models\GameSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GameSessionStateTransitionFactory extends Factory
{
    public function definition(): array
    {
        return ['game_session_id' => GameSession::factory(), 'from_state' => 'DRAFT', 'to_state' => 'REGISTRATION', 'actor_type' => 'user', 'actor_id' => User::factory(), 'occurred_at' => now()];
    }
}

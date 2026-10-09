<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GameSessionFactory extends Factory
{
    public function definition(): array
    {
        return ['game_id' => Game::factory(), 'organization_id' => fn (array $attributes): int => Game::findOrFail($attributes['game_id'])->organization_id,
            'created_by' => User::factory(), 'status' => 'DRAFT', 'timezone' => 'UTC'];
    }
}

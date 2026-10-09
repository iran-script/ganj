<?php

namespace Database\Factories;

use App\Models\GameSessionPlayer;
use App\Models\UserDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

class SessionReadyEventFactory extends Factory
{
    public function definition(): array
    {
        return ['game_session_player_id' => GameSessionPlayer::factory(), 'device_id' => fn (array $attributes): int => UserDevice::factory()->create(['user_id' => GameSessionPlayer::findOrFail($attributes['game_session_player_id'])->user_id])->id, 'ready' => true, 'occurred_at' => now()];
    }
}

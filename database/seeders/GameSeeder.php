<?php

namespace Database\Seeders;

use App\Models\Game;
use Illuminate\Database\Seeder;

class GameSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \LogicException('Game fixtures are local/testing only.');
        }
        Game::factory()->create(['status' => 'DRAFT']);
    }
}

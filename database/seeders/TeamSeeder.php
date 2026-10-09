<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Teams\TeamService;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    /** Explicit local/demo seeder; never included in the production DatabaseSeeder. */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Demo teams may only be seeded locally.');
        }
        $captain = User::factory()->create();
        app(TeamService::class)->execute($captain, 'create', ['name' => 'NEXO Demo Team']);
    }
}

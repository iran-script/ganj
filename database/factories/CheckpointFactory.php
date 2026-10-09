<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class CheckpointFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'name' => fake()->words(3, true),
            'type' => 'POINT', 'geom' => DB::raw('ST_SetSRID(ST_MakePoint(51.389,35.6892),4326)'),
            'radius_meters' => 50, 'config' => [], 'status' => 'ACTIVE'];
    }
}

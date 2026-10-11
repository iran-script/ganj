<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class QrTriggerFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'identifier_hash' => hash('sha256', fake()->uuid()), 'status' => 'ACTIVE', 'metadata' => []];
    }
}

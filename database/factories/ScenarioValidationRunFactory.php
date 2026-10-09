<?php

namespace Database\Factories;

use App\Models\ScenarioValidationRun;
use App\Models\ScenarioVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScenarioValidationRun> */
class ScenarioValidationRunFactory extends Factory
{
    public function definition(): array
    {
        return ['scenario_version_id' => ScenarioVersion::factory(), 'revision_token' => fn (array $a): string => ScenarioVersion::findOrFail($a['scenario_version_id'])->revision_token, 'status' => 'FAIL', 'errors' => [['code' => 'MISSING_START', 'path' => 'nodes']], 'warnings' => [], 'created_by' => User::factory(), 'created_at' => now()];
    }
}

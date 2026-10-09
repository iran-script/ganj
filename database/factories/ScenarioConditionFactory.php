<?php

namespace Database\Factories;

use App\Models\ScenarioCondition;
use App\Models\ScenarioVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ScenarioCondition> */
class ScenarioConditionFactory extends Factory
{
    public function definition(): array
    {
        return ['scenario_version_id' => ScenarioVersion::factory(), 'condition_group_id' => (string) Str::uuid(), 'left_operand' => 'score', 'operator' => '>', 'right_operand_json' => 100, 'value_type' => 'INTEGER', 'position' => 0, 'created_at' => now()];
    }
}

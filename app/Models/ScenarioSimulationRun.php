<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['*'])]
#[Hidden(['id', 'scenario_version_id', 'created_by'])]
class ScenarioSimulationRun extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['input_config' => 'array', 'result' => 'array', 'created_at' => 'immutable_datetime'];
    }
}

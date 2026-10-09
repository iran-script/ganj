<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['*'])]
#[Hidden(['id', 'scenario_version_id'])]
class ScenarioCondition extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['right_operand_json' => 'json', 'position' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}

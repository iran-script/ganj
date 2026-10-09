<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['*'])]
#[Hidden(['id', 'scenario_subflow_id'])]
class ScenarioSubflowVersion extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['graph_json' => 'array', 'published_at' => 'immutable_datetime'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['*'])]
#[Hidden(['id', 'scenario_version_id', 'from_node_id', 'to_node_id'])]
class ScenarioEdge extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'priority' => 'integer'];
    }
}

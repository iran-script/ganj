<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['*'])]
#[Hidden(['id', 'scenario_version_id'])]
class ScenarioNode extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected function casts(): array
    {
        return ['config' => 'array', 'position_x' => 'float', 'position_y' => 'float'];
    }
}

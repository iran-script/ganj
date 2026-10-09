<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['*'])]
#[Hidden(['id', 'scenario_version_id', 'node_id', 'subflow_version_id'])]
class ScenarioSubflowBinding extends Model
{
    use HasFactory;

    public $timestamps = false;
}

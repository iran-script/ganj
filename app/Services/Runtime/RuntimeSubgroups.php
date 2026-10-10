<?php

namespace App\Services\Runtime;

use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use Illuminate\Support\Collection;

class RuntimeSubgroups
{
    public function __construct(private RoleFairnessService $fairness) {}

    /** @param Collection<int, TeamNodeInstance> $targets */
    public function assign(TeamScenarioInstance $instance, TeamNodeInstance $split, Collection $targets): void
    {
        $groups = $split->node->config['groups'] ?? [];
        if ($groups === [] || $targets->isEmpty()) {
            return;
        }
        $players = $this->fairness->order($instance, $split, $instance->registration->players()->whereIn('participation_status', ['READY', 'ACTIVE', 'DISCONNECTED'])->get());
        $offset = 0;
        foreach ($targets->values() as $index => $target) {
            $size = (int) ($groups[$index] ?? 0);
            $members = $players->slice($offset, $size)->pluck('public_id')->values()->all();
            $offset += $size;
            $target->forceFill(['state_json' => ['subgroup_id' => $split->public_id.':'.($index + 1), 'subgroup_member_ids' => $members, 'subgroup_parent_id' => $split->public_id]])->save();
        }
    }
}

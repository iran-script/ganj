<?php

namespace App\Services\Runtime;

use App\Models\GameSessionPlayer;
use App\Models\TeamNodeInstance;

class BarrierEvaluator
{
    public function __construct(private ContributionService $contributions, private RuntimeRoles $roles) {}

    /** @return array{accepted:bool,complete:bool,contribution:bool,result:string,progress:int,role_code:?string} */
    public function evaluate(TeamNodeInstance $node, ?GameSessionPlayer $player, array $input): array
    {
        if (! $player || ($input['acknowledged'] ?? null) !== true) {
            return ['accepted' => false, 'complete' => false, 'contribution' => false, 'result' => 'REJECTED', 'progress' => 0, 'role_code' => null];
        }
        $instance = $node->instance;
        $this->roles->assertSubgroupEligible($node, $player);
        $role = $this->roles->roleFor($instance, $player);
        $summary = $this->contributions->summary($node, $node->node->config['synchronization_window_seconds'] ?? null);
        $duplicate = in_array($player->id, $summary['players'], true);
        $players = $summary['players'];
        if (! $duplicate) {
            $players[] = $player->id;
        }
        $roles = $summary['roles'];
        if (! $duplicate) {
            $roles[$player->id] = $role ?? 'UNKNOWN';
        }
        $requiredRoles = $node->node->config['required_roles'] ?? [];
        $roleComplete = $requiredRoles === [] || count(array_intersect($requiredRoles, array_values($roles))) === count(array_unique($requiredRoles));
        $minimum = (int) ($node->node->config['minimum_player_count'] ?? 1);
        $activeSlotOnly = ($node->node->config['active_slot_only'] ?? false) === true;
        $active = ! $activeSlotOnly || $role !== null;
        $count = count(array_unique($players));
        $complete = $active && $summary['within_window'] && $roleComplete && $count >= $minimum;

        return ['accepted' => true, 'complete' => $complete, 'contribution' => ! $duplicate, 'result' => $duplicate ? 'DUPLICATE' : 'ACCEPTED', 'progress' => $count, 'role_code' => $role];
    }
}

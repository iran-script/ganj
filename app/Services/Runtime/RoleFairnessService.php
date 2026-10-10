<?php

namespace App\Services\Runtime;

use App\Models\GameSessionPlayer;
use App\Models\PlayerRoleAssignment;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use Illuminate\Support\Collection;

class RoleFairnessService
{
    /** @return Collection<int, GameSessionPlayer> */
    public function order(TeamScenarioInstance $instance, TeamNodeInstance $node, Collection $players, bool $seededRandom = false): Collection
    {
        $history = PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)
            ->selectRaw('game_session_player_id, count(*) as assignment_count, max(id) as last_assignment_id')
            ->groupBy('game_session_player_id')
            ->get()
            ->keyBy('game_session_player_id');

        return $players->sortBy(function (GameSessionPlayer $player) use ($history, $instance, $node, $seededRandom): array {
            $record = $history->get($player->id);
            $tie = hash_hmac('sha256', $node->node->node_key.':'.$player->public_id, $instance->random_seed);

            return $seededRandom
                ? [$tie]
                : [$record?->assignment_count ?? 0, $record?->last_assignment_id ?? 0, $tie];
        })->values();
    }
}

<?php

namespace App\Services\Runtime;

use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\PlayerRoleAssignment;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Models\UserDevice;

class SupportParticipationPolicy
{
    public function assertActionAllowed(TeamScenarioInstance $instance, TeamNodeInstance $node, GameSessionPlayer $player): void
    {
        $members = $node->state_json['subgroup_member_ids'] ?? null;
        if (is_array($members) && ! in_array($player->public_id, $members, true)) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'This action is restricted to another subgroup.', [], 403);
        }
        if (($node->node->config['support_policy'] ?? 'COLLABORATE') === 'DENY' && ! $this->isPrimary($instance, $player)) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Support Players cannot perform this action.', [], 403);
        }
    }

    public function isPrimary(TeamScenarioInstance $instance, GameSessionPlayer $player): bool
    {
        return PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)
            ->where('game_session_player_id', $player->id)
            ->whereNull('released_at')
            ->exists();
    }

    public function supports(UserDevice $device, string $capability): bool
    {
        $capabilities = $device->getAttribute('capabilities');
        if (! is_array($capabilities)) {
            return false;
        }

        return in_array($capability, $capabilities, true);
    }
}

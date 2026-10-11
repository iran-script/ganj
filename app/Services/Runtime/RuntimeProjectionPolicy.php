<?php

namespace App\Services\Runtime;

use App\Models\GameSessionPlayer;
use App\Models\TeamNodeInstance;

class RuntimeProjectionPolicy
{
    public function __construct(private RuntimeRoles $roles) {}

    public function config(TeamNodeInstance $node, ?GameSessionPlayer $player): array
    {
        $config = $node->node->config;
        $safe = array_intersect_key($config, array_flip(['offline_submission_allowed', 'prompt', 'text', 'options', 'multi_select', 'duration_seconds', 'minimum_player_count', 'subgroup_id']));
        if ($player && isset($config['role_content'])) {
            $role = $this->roles->roleFor($node->instance, $player);
            if ($role !== null && isset($config['role_content'][$role]) && is_array($config['role_content'][$role])) {
                $safe = array_replace($safe, array_intersect_key($config['role_content'][$role], array_flip(['prompt', 'text', 'options'])));
            }
        }
        if (isset($node->state_json['subgroup_id'])) {
            $safe['subgroup_id'] = $node->state_json['subgroup_id'];
            if ($player) {
                $safe['subgroup_member'] = in_array($player->public_id, $node->state_json['subgroup_member_ids'] ?? [], true);
            }
        }
        if ($node->node->node_type === 'Give Hint') {
            return [];
        }

        return $safe;
    }
}

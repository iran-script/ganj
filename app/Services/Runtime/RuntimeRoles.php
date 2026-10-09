<?php

namespace App\Services\Runtime;

use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\PlayerRoleAssignment;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Models\User;

class RuntimeRoles
{
    public const CAPABILITIES = ['SCANNER' => 'SCAN_QR', 'NAVIGATOR' => 'NAVIGATE', 'DECODER' => 'DECODE', 'AR_OPERATOR' => 'OPERATE_AR'];

    public function __construct(private RuntimeEvents $events) {}

    public function release(TeamScenarioInstance $instance, PlayerRoleAssignment $assignment, ?User $actor = null): void
    {
        if ($assignment->team_scenario_instance_id !== $instance->id) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Assignment not found.', [], 404);
        }
        if ($assignment->released_at === null) {
            $assignment->forceFill(['released_at' => now()])->save();
            $this->events->record('role_released', $instance, TeamNodeInstance::findOrFail($assignment->node_instance_id), $actor,
                data: ['assignment_id' => $assignment->public_id, 'role_code' => $assignment->role_code]);
        }
    }

    public function assign(TeamScenarioInstance $instance, TeamNodeInstance $node, GameSessionPlayer $player, string $role, int $slot, string $source, ?User $actor = null): PlayerRoleAssignment
    {
        if ($node->team_scenario_instance_id !== $instance->id || $player->game_session_team_id !== $instance->game_session_team_id
            || ! in_array($player->participation_status, ['READY', 'ACTIVE', 'DISCONNECTED'], true) || ! isset(self::CAPABILITIES[$role]) || $slot < 1 || $slot > 4) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Invalid runtime role assignment.', [], 409);
        }
        $active = PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)->whereNull('released_at')->get();
        if ($active->count() >= 4 || $active->contains('slot_no', $slot) || $active->contains('role_code', $role) || $active->contains('game_session_player_id', $player->id)) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'The primary challenge slot is occupied.', [], 409);
        }
        $assignment = new PlayerRoleAssignment;
        $assignment->forceFill(['team_scenario_instance_id' => $instance->id, 'node_instance_id' => $node->id,
            'game_session_player_id' => $player->id, 'role_code' => $role, 'slot_no' => $slot, 'assigned_at' => now(), 'assignment_source' => $source])->save();
        $this->events->record('role_assigned', $instance, $node, $actor, $player, ['assignment_id' => $assignment->public_id, 'role_code' => $role, 'slot_no' => $slot, 'assignment_source' => $source]);

        return $assignment;
    }

    public function rotate(TeamScenarioInstance $instance, TeamNodeInstance $node): void
    {
        $roles = $node->node->config['roles'];
        if (count(array_unique($roles)) !== count($roles)) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Role codes must be distinct.', [], 409);
        }
        $players = $instance->registration->players()->whereIn('participation_status', ['READY', 'ACTIVE', 'DISCONNECTED'])->get();
        if ($players->count() < count($roles)) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Not enough eligible role participants.', [], 409);
        }
        $last = PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)->selectRaw('game_session_player_id, max(id) as last_assignment')->groupBy('game_session_player_id')->pluck('last_assignment', 'game_session_player_id');
        $players = $players->sortBy(fn (GameSessionPlayer $p): array => [$last[$p->id] ?? 0, hash_hmac('sha256', $node->node->node_key.':'.$p->public_id, $instance->random_seed)])->values();
        foreach (PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)->whereNull('released_at')->get() as $assignment) {
            $this->release($instance, $assignment);
        }
        foreach ($roles as $slot => $role) {
            $this->assign($instance, $node, $players[$slot], $role, $slot + 1, 'LEAST_RECENTLY_USED');
        }
    }

    public function eligible(TeamScenarioInstance $instance, TeamNodeInstance $node, GameSessionPlayer $player): void
    {
        $type = $node->node->node_type;
        $required = ['QR' => 'SCANNER', 'GPS / Location' => 'NAVIGATOR', 'Checkpoint' => 'NAVIGATOR',
            'AR Challenge' => 'AR_OPERATOR', 'Puzzle' => 'DECODER', 'Password' => 'DECODER', 'Text Answer' => 'DECODER', 'Multiple Choice' => 'DECODER'];
        $role = $type === 'Specific Player' ? $node->node->config['role'] : ($required[$type] ?? null);
        $active = PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)->whereNull('released_at');
        if ($role && ($type === 'Specific Player' || (clone $active)->exists()) && ! (clone $active)->where('game_session_player_id', $player->id)->where('role_code', $role)->exists()) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'The current runtime role does not allow this action.', [], 403);
        }
        if ($type === 'Random Player' && ($node->state_json['selected_player_id'] ?? null) !== $player->public_id) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'This task belongs to another selected Player.', [], 403);
        }
    }
}

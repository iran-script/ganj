<?php

namespace App\Services\Runtime;

use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\PlayerRoleAssignment;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Models\User;
use App\Models\UserDevice;

class RuntimeRoles
{
    public const CAPABILITIES = ['SCANNER' => 'SCAN_QR', 'NAVIGATOR' => 'NAVIGATE', 'DECODER' => 'DECODE', 'AR_OPERATOR' => 'OPERATE_AR'];

    public function __construct(private RuntimeEvents $events, private RoleAssignmentPolicy $policies, private SupportParticipationPolicy $support) {}

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
        $players = $this->policies->players($instance, $node, $roles);
        if ($players->count() < count($roles)) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Not enough eligible role participants.', [], 409);
        }
        $last = PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)->selectRaw('game_session_player_id, max(id) as last_assignment')->groupBy('game_session_player_id')->pluck('last_assignment', 'game_session_player_id');
        if ($node->node->node_type !== 'AssignRole') {
            $players = $players->sortBy(fn (GameSessionPlayer $p): array => [$last[$p->id] ?? 0, hash_hmac('sha256', $node->node->node_key.':'.$p->public_id, $instance->random_seed)])->values();
        }
        foreach (PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)->whereNull('released_at')->get() as $assignment) {
            $this->release($instance, $assignment);
        }
        foreach ($roles as $slot => $role) {
            $this->assign($instance, $node, $players[$slot], $role, $slot + 1, $node->node->config['assignment_policy'] ?? 'LEAST_RECENTLY_USED');
        }
    }

    public function eligible(TeamScenarioInstance $instance, TeamNodeInstance $node, GameSessionPlayer $player, ?UserDevice $device = null): void
    {
        $this->support->assertActionAllowed($instance, $node, $player);
        $requiredCapability = $node->node->config['required_device_capability'] ?? null;
        if ($requiredCapability !== null && (! $device || ! $this->support->supports($device, $requiredCapability))) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'The current Device lacks the required capability.', [], 403);
        }
        $type = $node->node->node_type;
        $policy = $node->node->config['role_policy'] ?? null;
        if ($type === 'QR' && $policy === 'ANY_TEAM_MEMBER') {
            return;
        }
        if ($type === 'QR' && in_array($policy, ['ACTIVE_SLOT', 'SPECIFIC_ROLE', 'SPECIFIC_PLAYER'], true)) {
            $assigned = PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)->whereNull('released_at')
                ->where('game_session_player_id', $player->id);
            $allowed = match ($policy) {
                'ACTIVE_SLOT' => $assigned->exists(),
                'SPECIFIC_ROLE' => $assigned->where('role_code', $node->node->config['role_code'])->exists(),
                'SPECIFIC_PLAYER' => ($node->state_json['selected_player_id'] ?? $node->node->config['specific_player_id'] ?? null) === $player->public_id,
            };
            if (! $allowed) {
                throw new ApiException('ACTION_NOT_ALLOWED', 'The QR policy does not allow this Player.', [], 403);
            }

            return;
        }
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

    public function roleFor(TeamScenarioInstance $instance, GameSessionPlayer $player): ?string
    {
        return PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)
            ->where('game_session_player_id', $player->id)
            ->whereNull('released_at')
            ->value('role_code');
    }

    public function assertSubgroupEligible(TeamNodeInstance $node, GameSessionPlayer $player): void
    {
        $members = $node->state_json['subgroup_member_ids'] ?? null;
        if (is_array($members) && ! in_array($player->public_id, $members, true)) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'This action is restricted to another subgroup.', [], 403);
        }
    }
}

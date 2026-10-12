<?php

namespace App\Services\Runtime;

use App\Models\Checkpoint;
use App\Models\GameSessionPlayer;
use App\Models\PlayerRoleAssignment;
use App\Models\RuntimeEvent;
use App\Models\RuntimeTimer;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;

class RuntimeProjection
{
    public function __construct(private RuntimeTimers $timers, private RuntimeProjectionPolicy $policy) {}

    public function instance(TeamScenarioInstance $instance, ?GameSessionPlayer $player = null): array
    {
        $nodes = TeamNodeInstance::where('team_scenario_instance_id', $instance->id)->where('status', '<>', 'LOCKED')->with('node')->orderBy('id')->get();
        $contributions = RuntimeEvent::whereIn('aggregate_id', $nodes->pluck('public_id'))->where('payload->data->contribution', true)->selectRaw('aggregate_id, count(*) as contribution_count')->groupBy('aggregate_id')->pluck('contribution_count', 'aggregate_id');
        $players = $instance->registration->players()->get()->keyBy('id');
        $roles = PlayerRoleAssignment::where('team_scenario_instance_id', $instance->id)->whereNull('released_at')->get();
        $checkpoints = Checkpoint::where('organization_id', $instance->session->organization_id)
            ->whereIn('public_id', $nodes->filter(fn (TeamNodeInstance $node): bool => in_array($node->node->node_type, ['GPS / Location', 'Checkpoint'], true))
                ->map(fn (TeamNodeInstance $node): ?string => $node->node->config['checkpoint_id'] ?? null)->filter())
            ->selectRaw('public_id,name,type,radius_meters,ST_AsGeoJSON(geom) AS geojson')->get()->keyBy('public_id')->all();

        return ['id' => $instance->public_id, 'session_id' => $instance->session->public_id, 'team_id' => $instance->registration->team->public_id,
            'scenario_version_id' => $instance->version->public_id, 'status' => $instance->status,
            'effective_elapsed_seconds' => $this->timers->instanceElapsed($instance),
            'started_at' => $instance->started_at?->toISOString(), 'completed_at' => $instance->completed_at?->toISOString(),
            'nodes' => $nodes->map(fn (TeamNodeInstance $node): array => $this->node($node, (int) ($contributions[$node->public_id] ?? 0), $checkpoints, $player))->all(),
            'active_node_ids' => $nodes->whereIn('status', ['AVAILABLE', 'ACTIVE'])->pluck('public_id')->values()->all(),
            'private_messages' => $player ? $nodes->filter(fn (TeamNodeInstance $node): bool => $node->node->node_type === 'RandomPlayer'
                && ($node->state_json['selected_player_id'] ?? null) === $player->public_id && isset($node->node->config['private_text']))
                ->map(fn (TeamNodeInstance $node): array => ['id' => $node->public_id, 'text' => $node->node->config['private_text']])->values()->all() : [],
            'roles' => $roles->map(fn (PlayerRoleAssignment $r): array => ['id' => $r->public_id, 'player_id' => $players[$r->game_session_player_id]->public_id,
                'role_code' => $r->role_code, 'slot_no' => $r->slot_no])->all(),
            'player' => $player ? ['id' => $player->public_id, 'participation_status' => $player->participation_status,
                'device_id' => $player->device?->public_id, 'support' => ! $roles->contains('game_session_player_id', $player->id)] : null,
            'timers' => RuntimeTimer::where('team_scenario_instance_id', $instance->id)->get()->map(fn (RuntimeTimer $timer): array => [
                'id' => $timer->public_id, 'node_instance_id' => $timer->node_instance_id ? $nodes->firstWhere('id', $timer->node_instance_id)?->public_id : null,
                'status' => $timer->status, 'duration_seconds' => $timer->duration_seconds, 'effective_elapsed_seconds' => $this->timers->elapsed($timer)])->all()];
    }

    public function node(TeamNodeInstance $node, ?int $progress = null, ?array $checkpoints = null, ?GameSessionPlayer $player = null): array
    {
        $config = $node->node->config;
        $safe = $this->policy->config($node, $player);
        if ($node->status !== 'LOCKED' && in_array($node->node->node_type, ['GPS / Location', 'Checkpoint'], true)) {
            if ($node->node->node_type === 'GPS / Location' && isset($config['latitude'], $config['longitude'], $config['radius_m'])) {
                $safe['gps'] = ['latitude' => $config['latitude'], 'longitude' => $config['longitude'], 'radius_m' => $config['radius_m'],
                    'accuracy_m' => $config['accuracy_m'] ?? null, 'dwell_seconds' => $config['dwell_seconds'] ?? 0];
            }
            $checkpoint = isset($config['checkpoint_id']) ? ($checkpoints !== null ? ($checkpoints[$config['checkpoint_id']] ?? null)
                : Checkpoint::where('public_id', $config['checkpoint_id'])->where('organization_id', $node->instance->session->organization_id)
                    ->selectRaw('public_id,name,type,radius_meters,ST_AsGeoJSON(geom) AS geojson')->first()) : null;
            if ($checkpoint && ! isset($safe['gps'])) {
                $safe['checkpoint'] = ['id' => $checkpoint->public_id, 'name' => $checkpoint->name, 'type' => $checkpoint->type,
                    'geom' => json_decode($checkpoint->geojson, true), 'radius_meters' => json_decode(json_encode($checkpoint->radius_meters, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)];
            }
        }
        if ($node->node->node_type === 'Give Hint') {
            $safe = [];
        }

        return ['id' => $node->public_id, 'node_key' => $node->node->node_key, 'node_type' => $node->node->node_type,
            'progress' => in_array($node->node->node_type, ['Team Together', 'TeamTogether', 'Barrier', 'SplitInformation'], true) ? ($progress ?? RuntimeEvent::where('aggregate_id', $node->public_id)->where('payload->data->contribution', true)->count()) : null,
            'selected_player_id' => $node->state_json['selected_player_id'] ?? null, 'title' => $node->node->title, 'status' => $node->status, 'config' => $safe, 'attempt_count' => $node->attempt_count,
            'version_no' => $node->version_no, 'started_at' => $node->started_at?->toISOString(), 'completed_at' => $node->completed_at?->toISOString()];
    }
}

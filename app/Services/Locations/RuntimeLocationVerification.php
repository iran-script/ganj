<?php

namespace App\Services\Locations;

use App\Exceptions\ApiException;
use App\Models\Checkpoint;
use App\Models\GameSessionPlayer;
use App\Models\PlayerRoleAssignment;
use App\Models\TeamNodeInstance;
use App\Models\UserDevice;
use App\Services\Runtime\LocationVerification;
use App\Services\Runtime\RuntimeRoles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RuntimeLocationVerification implements LocationVerification
{
    public function __construct(private GeofenceVerifier $verifier, private LocationEvents $events) {}

    public function execute(TeamNodeInstance $node, ?GameSessionPlayer $player, array $evidence, string $commandId): array
    {
        if (DB::transactionLevel() === 0 || ! $player || $node->status !== 'ACTIVE'
            || $node->instance->status !== 'ACTIVE' || $node->instance->session->status !== 'RUNNING'
            || $player->game_session_team_id !== $node->instance->game_session_team_id) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Location verification requires active Runtime context.', [], 409);
        }
        $config = $node->node->config;
        $checkpoint = isset($config['checkpoint_id']) ? Checkpoint::where('public_id', $config['checkpoint_id'])
            ->where('organization_id', $node->instance->session->organization_id)->first() : null;
        $inline = isset($config['latitude'], $config['longitude'], $config['radius_m']);
        if (! $inline && ! $checkpoint) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'GPS location resource not found.', [], 404);
        }
        $policy = $this->verifier->policy($checkpoint, $node);
        $hash = hash('sha256', json_encode($policy));
        UserDevice::whereIn('id', GameSessionPlayer::where('game_session_team_id', $player->game_session_team_id)->select('active_device_id'))->orderBy('id')->lockForUpdate()->get();
        $rows = DB::select("SELECT p.id,p.public_id,l.server_received_at,l.client_timestamp,l.eligible,
            l.location_event_id,l.location_event_received_at,g.result,g.distance_meters,g.accuracy,g.dwell_started_at,g.evidence_count,g.policy_hash,g.evaluated_at
            FROM game_session_players p JOIN player_latest_locations l ON l.game_session_player_id=p.id
            JOIN user_devices d ON d.id=p.active_device_id AND d.id=l.device_id AND d.user_id=p.user_id AND d.revoked_at IS NULL
            JOIN users u ON u.id=p.user_id AND u.status='ACTIVE'
            JOIN game_session_teams t ON t.id=p.game_session_team_id AND t.status='ACTIVE'
            LEFT JOIN LATERAL (SELECT * FROM geofence_evaluations e WHERE e.player_id=p.id AND e.node_instance_id=? ORDER BY e.id DESC LIMIT 1) g ON true
            WHERE p.game_session_id=? AND p.game_session_team_id=? AND p.participation_status IN ('ACTIVE','DISCONNECTED')
            ORDER BY p.id,g.id DESC", [$node->id, $player->game_session_id, $player->game_session_team_id]);
        $lastResume = DB::table('runtime_events')->where('aggregate_id', $node->instance->public_id)->where('event_type', 'runtime.resumed.v1')->max('occurred_at');
        $eligible = [];
        foreach ($rows as $row) {
            if ((! $inline && $checkpoint->status !== 'ACTIVE') || ! $row->eligible || $row->result !== 'INSIDE' || $row->policy_hash !== $hash
                || CarbonImmutable::parse($row->server_received_at)->lessThan(now()->subSeconds(config('location.fresh_seconds')))
                || CarbonImmutable::parse($row->client_timestamp)->lessThan(now()->subSeconds(config('location.fresh_seconds')))
                || ($lastResume && CarbonImmutable::parse($row->server_received_at)->lessThanOrEqualTo(CarbonImmutable::parse($lastResume)))
                || $row->evaluated_at !== $row->server_received_at
                || $row->accuracy > $policy['min_accuracy'] || $row->dwell_started_at === null) {
                continue;
            }
            $elapsed = CarbonImmutable::parse($row->dwell_started_at)->diffInSeconds(CarbonImmutable::parse($row->evaluated_at), false);
            if ($elapsed < $policy['dwell_time'] || ($policy['dwell_time'] > 0 && $row->evidence_count < 2)) {
                continue;
            }
            $eligible[] = $row->id;
        }
        $roles = PlayerRoleAssignment::where('team_scenario_instance_id', $node->team_scenario_instance_id)
            ->whereNull('released_at')->whereIn('game_session_player_id', $eligible)->pluck('role_code')->all();
        $capabilities = array_map(fn (string $role): string => RuntimeRoles::CAPABILITIES[$role], $roles);
        $complete = count($eligible) >= $policy['required_player_count'] && ! array_diff($policy['required_roles'], $roles)
            && ! array_diff($policy['required_capabilities'], $capabilities);
        $witness = collect($rows)->first(fn (object $row): bool => in_array($row->id, $eligible, true));
        $eventReference = $witness ? DB::table('location_events')->where('id', $witness->location_event_id)
            ->where('server_received_at', $witness->location_event_received_at)->value('public_id') : null;
        $this->events->record($complete ? 'checkpoint_verified' : 'checkpoint_rejected', $player,
            ['checkpoint_id' => $checkpoint?->public_id, 'gps_coordinates' => $inline ? ['latitude' => $config['latitude'], 'longitude' => $config['longitude'], 'radius_m' => $config['radius_m']] : null,
                'node_instance_id' => $node->public_id, 'command_id' => $commandId,
                'player_id' => $witness?->public_id ?? $player->public_id, 'eligible_player_count' => count($eligible),
                'distance_meters' => $witness?->distance_meters, 'accuracy' => $witness?->accuracy, 'location_event_id' => $eventReference]);

        return ['accepted' => $complete, 'complete' => $complete, 'progress' => count($eligible)];
    }
}

<?php

namespace App\Services\Locations;

use App\Models\Checkpoint;
use App\Models\GameSessionPlayer;
use App\Models\TeamNodeInstance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GeofenceVerifier
{
    public function __construct(private LocationEvents $events) {}

    public function policy(?Checkpoint $checkpoint, TeamNodeInstance $node): array
    {
        $policy = array_replace(['min_accuracy' => config('location.min_accuracy'), 'dwell_time' => 0,
            'required_player_count' => 1, 'required_roles' => [], 'required_capabilities' => []], $checkpoint?->config ?? [], $node->node->config);
        if (isset($node->node->config['accuracy_m'])) {
            $policy['min_accuracy'] = $node->node->config['accuracy_m'];
        }
        if (isset($node->node->config['dwell_seconds'])) {
            $policy['dwell_time'] = $node->node->config['dwell_seconds'];
        }

        return $policy;
    }

    /** @return array{result:string,distance_meters:float,accuracy:float} */
    public function classify(Checkpoint $checkpoint, object $location, float $minAccuracy): array
    {
        $row = DB::selectOne("SELECT c.type,c.radius_meters,
            ST_Distance(c.geom::geography,l.geom::geography) AS distance,
            CASE WHEN c.type='ZONE' THEN ST_Distance(ST_Boundary(c.geom)::geography,l.geom::geography) ELSE 0 END AS boundary_distance,
            ST_Covers(c.geom,l.geom) AS covered
            FROM checkpoints c CROSS JOIN player_latest_locations l WHERE c.id=? AND l.game_session_player_id=?",
            [$checkpoint->id, $location->game_session_player_id]);
        $accuracy = (float) $location->accuracy;
        $distance = (float) $row->distance;
        $result = 'UNCERTAIN';
        if ($accuracy <= $minAccuracy) {
            if ($row->type === 'POINT') {
                $result = $distance + $accuracy <= $row->radius_meters ? 'INSIDE'
                    : ($distance - $accuracy > $row->radius_meters ? 'OUTSIDE' : 'UNCERTAIN');
            } elseif ($accuracy == 0 || (float) $row->boundary_distance > $accuracy) {
                $result = $row->covered ? 'INSIDE' : 'OUTSIDE';
            }
        }

        return ['result' => $result, 'distance_meters' => $distance, 'accuracy' => $accuracy];
    }

    /** @return array{result:string,distance_meters:float,accuracy:float} */
    public function classifyInline(TeamNodeInstance $node, object $location, float $minAccuracy): array
    {
        $config = $node->node->config;
        $row = DB::selectOne('SELECT ST_Distance(ST_SetSRID(ST_MakePoint(?, ?),4326)::geography,l.geom::geography) AS distance
            FROM player_latest_locations l WHERE l.game_session_player_id=?', [$config['longitude'], $config['latitude'], $location->game_session_player_id]);
        $accuracy = (float) $location->accuracy;
        $distance = $row ? (float) $row->distance : INF;
        $result = 'UNCERTAIN';
        if ($accuracy <= $minAccuracy) {
            $result = $distance + $accuracy <= (float) $config['radius_m'] ? 'INSIDE'
                : ($distance - $accuracy > (float) $config['radius_m'] ? 'OUTSIDE' : 'UNCERTAIN');
        }

        return ['result' => $result, 'distance_meters' => $distance, 'accuracy' => $accuracy];
    }

    /** Incremental evidence only; never changes Runtime state. Called under the Session lock. */
    public function observe(GameSessionPlayer $player, object $location): void
    {
        $nodes = TeamNodeInstance::with(['node', 'instance.session'])->where('status', 'ACTIVE')
            ->whereHas('instance', fn ($q) => $q->where('game_session_team_id', $player->game_session_team_id)->where('status', 'ACTIVE'))
            ->whereHas('node', fn ($q) => $q->whereIn('node_type', ['GPS / Location', 'Checkpoint', 'QR'])
                ->where(fn ($query) => $query->whereNotNull('config->checkpoint_id')->orWhereNotNull('config->latitude')))->get();
        $checkpoints = Checkpoint::where('organization_id', $player->session->organization_id)
            ->whereIn('public_id', $nodes->map(fn ($node) => $node->node->config['checkpoint_id'] ?? null)->filter())->get()->keyBy('public_id');
        foreach ($nodes as $node) {
            $config = $node->node->config;
            $checkpoint = isset($config['checkpoint_id']) ? ($checkpoints[$config['checkpoint_id']] ?? null) : null;
            $inline = isset($config['latitude'], $config['longitude'], $config['radius_m']);
            if (! $inline && (! $checkpoint || $checkpoint->status !== 'ACTIVE')) {
                continue;
            }
            $policy = $this->policy($checkpoint, $node);
            $hash = hash('sha256', json_encode($policy));
            $previous = DB::table('geofence_evaluations')->where('node_instance_id', $node->id)->where('player_id', $player->id)->orderByDesc('id')->first();
            if ($previous && $previous->location_event_id === $location->location_event_id && $previous->location_event_received_at === $location->location_event_received_at) {
                continue;
            }
            $result = $inline ? $this->classifyInline($node, $location, $policy['min_accuracy']) : $this->classify($checkpoint, $location, $policy['min_accuracy']);
            $received = CarbonImmutable::parse($location->server_received_at);
            if (! $location->eligible || $player->session->status !== 'RUNNING') {
                $result['result'] = 'UNCERTAIN';
            }
            $lastResume = DB::table('runtime_events')->where('aggregate_id', $node->instance->public_id)->where('event_type', 'runtime.resumed.v1')->max('occurred_at');
            $continuous = (! $lastResume || ($previous && CarbonImmutable::parse($previous->evaluated_at)->greaterThan(CarbonImmutable::parse($lastResume))))
                && $result['result'] === 'INSIDE' && $previous?->result === 'INSIDE' && $previous->policy_hash === $hash
                && $previous->dwell_started_at !== null
                && CarbonImmutable::parse($previous->evaluated_at)->diffInSeconds($received, false) <= config('location.max_sampling_gap_seconds')
                && CarbonImmutable::parse($previous->evaluated_at)->lessThan($received);
            $start = $result['result'] === 'INSIDE' ? ($continuous ? $previous->dwell_started_at : $received) : null;
            DB::table('geofence_evaluations')->insert($result + ['game_session_id' => $player->game_session_id, 'player_id' => $player->id,
                'checkpoint_id' => $checkpoint?->id, 'node_instance_id' => $node->id, 'location_event_id' => $location->location_event_id,
                'location_event_received_at' => $location->location_event_received_at, 'evaluated_at' => $received,
                'dwell_started_at' => $start, 'evidence_count' => $continuous ? $previous->evidence_count + 1 : 1, 'policy_hash' => $hash]);
            if ($previous?->result !== $result['result']) {
                $this->events->record(match ($result['result']) {
                    'INSIDE' => 'geofence_entered', 'OUTSIDE' => 'geofence_exited', default => 'geofence_uncertain'
                }, $player,
                    $result + ['checkpoint_id' => $checkpoint?->public_id, 'node_instance_id' => $node->public_id]);
            }
        }
    }
}

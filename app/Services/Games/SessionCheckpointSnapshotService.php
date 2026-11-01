<?php

namespace App\Services\Games;

use App\Exceptions\ApiException;
use App\Models\GameSession;
use App\Models\ScenarioVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionCheckpointSnapshotService
{
    /** @param array<string,string> $assignedVersions Team public ID to scenario version public ID */
    public function capture(GameSession $session, array $assignedVersions, Carbon $snapshotAt): void
    {
        $versionPublicIds = collect(array_values($assignedVersions))
            ->filter(fn (mixed $publicId): bool => is_string($publicId) && Str::isUuid($publicId))
            ->unique()->values();
        $versionIds = ScenarioVersion::query()->whereIn('public_id', $versionPublicIds)
            ->where('status', 'PUBLISHED')->whereHas('scenario', fn ($query) => $query->where('organization_id', $session->organization_id))
            ->pluck('id');
        $checkpointIds = DB::table('scenario_nodes')->whereIn('scenario_version_id', $versionIds)
            ->whereRaw("config->>'checkpoint_id' IS NOT NULL")->selectRaw("config->>'checkpoint_id' AS checkpoint_id")
            ->pluck('checkpoint_id')->all();
        $subflowVersions = DB::table('scenario_subflow_bindings')->whereIn('scenario_version_id', $versionIds)
            ->pluck('subflow_version_id')->all();
        $seenSubflows = [];
        while ($subflowVersions !== []) {
            $current = array_values(array_diff(array_unique($subflowVersions), $seenSubflows));
            $subflowVersions = [];
            if ($current === []) {
                break;
            }
            array_push($seenSubflows, ...$current);
            $graphs = DB::table('scenario_subflow_versions')->whereIn('id', $current)->pluck('graph_json');
            foreach ($graphs as $graphJson) {
                $graph = is_string($graphJson) ? json_decode($graphJson, true, flags: JSON_THROW_ON_ERROR) : $graphJson;
                foreach ($graph['nodes'] ?? [] as $node) {
                    if (isset($node['config']['checkpoint_id'])) {
                        $checkpointIds[] = $node['config']['checkpoint_id'];
                    }
                }
                foreach ($graph['subflows'] ?? [] as $binding) {
                    $nestedVersionId = DB::table('scenario_subflows as subflows')
                        ->join('scenario_subflow_versions as versions', 'versions.scenario_subflow_id', '=', 'subflows.id')
                        ->where('subflows.public_id', $binding['subflow_id'] ?? '')
                        ->where('versions.version_no', $binding['version_no'] ?? -1)->value('versions.id');
                    if ($nestedVersionId) {
                        $subflowVersions[] = $nestedVersionId;
                    }
                }
            }
        }
        $checkpointIds = collect($checkpointIds)->filter()->unique()->sort()->values();

        foreach ($checkpointIds as $checkpointPublicId) {
            $checkpoint = DB::table('checkpoints')->where('public_id', $checkpointPublicId)
                ->where('organization_id', $session->organization_id)->lockForUpdate()->first();
            if (! $checkpoint || $checkpoint->type !== 'POINT' || $checkpoint->status !== 'ACTIVE') {
                throw new ApiException('SESSION_NOT_READY', 'A referenced active location is unavailable.', [], 409);
            }
            DB::statement('INSERT INTO game_session_checkpoint_snapshots
                (game_session_id,checkpoint_id,geom,radius_meters,config,status,location_version,snapshot_at)
                SELECT ?,id,geom,radius_meters,config,status,updated_at,? FROM checkpoints WHERE id=?',
                [$session->id, $snapshotAt, $checkpoint->id]);
        }
    }
}

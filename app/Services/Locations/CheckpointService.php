<?php

namespace App\Services\Locations;

use App\Exceptions\ApiException;
use App\Models\Checkpoint;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Organizations\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckpointService
{
    public function __construct(private OrganizationScope $scope, private LocationInput $input, private AuditService $audit) {}

    public function query(User $actor): Builder
    {
        return Checkpoint::whereIn('organization_id', $this->scope->organizations($actor, true)->select('id'));
    }

    public function resolve(User $actor, string $id): Checkpoint
    {
        $checkpoint = Str::isUuid($id) ? $this->query($actor)->where('public_id', $id)->first() : null;
        if (! $checkpoint) {
            throw $this->scope->notFound();
        }

        return $checkpoint;
    }

    public function save(User $actor, array $input, ?string $id = null): array
    {
        return DB::transaction(function () use ($actor, $input, $id): array {
            $this->input->validate($input, ['organization_id' => $id ? 'prohibited' : 'required|uuid',
                'name' => ($id ? 'sometimes' : 'required').'|string|max:255',
                'type' => ($id ? 'sometimes' : 'required').'|in:POINT,ZONE',
                'geom' => ($id ? 'sometimes' : 'required').'|array:type,coordinates', 'geom.type' => 'required_with:geom|in:Point,Polygon,MultiPolygon',
                'geom.coordinates' => 'required_with:geom|array', 'radius_meters' => 'sometimes|nullable|numeric|min:0.01|max:100000',
                'config' => 'sometimes|array', 'metadata' => 'sometimes|array:category,description', 'metadata.category' => 'sometimes|string|max:100',
                'metadata.description' => 'sometimes|string|max:2000', 'status' => 'sometimes|in:ACTIVE,INACTIVE,ARCHIVED']);
            $checkpoint = $id ? $this->resolve($actor, $id) : new Checkpoint;
            $organization = $id ? $checkpoint->organization->public_id : $input['organization_id'];
            $context = $this->scope->resolve($actor, $organization, true);
            $context->authorize('update');
            if ($context->organization->status !== 'ACTIVE') {
                throw new ApiException('ACTION_NOT_ALLOWED', 'Organization is not active.', [], 409);
            }
            if ($id) {
                $checkpoint = Checkpoint::whereKey($checkpoint->id)->lockForUpdate()->sole();
                if (DB::table('scenario_nodes')->join('scenario_versions', 'scenario_versions.id', '=', 'scenario_nodes.scenario_version_id')
                    ->where('scenario_nodes.config->checkpoint_id', $checkpoint->public_id)->where('scenario_versions.status', '<>', 'DRAFT')->exists()
                    && array_intersect(array_keys($input), ['type', 'geom', 'radius_meters', 'config'])) {
                    throw new ApiException('CONFLICT', 'Published checkpoint geometry and verification policy are immutable; create a new checkpoint.', [], 409);
                }
            }
            $config = $input['config'] ?? $checkpoint->config ?? [];
            $this->input->validate($config, $this->input->policyRules());
            $type = $input['type'] ?? $checkpoint->type;
            $radius = array_key_exists('radius_meters', $input) ? $input['radius_meters'] : $checkpoint->radius_meters;
            if (($type === 'POINT' && (! is_numeric($radius) || $radius <= 0)) || ($type === 'ZONE' && $radius !== null)) {
                throw new ApiException('LOCATION_INVALID', 'Radius must be present only for POINT checkpoints.', [], 422);
            }
            $geom = isset($input['geom']) ? json_encode($input['geom'], JSON_THROW_ON_ERROR) : DB::table('checkpoints')->where('id', $checkpoint->id)->value(DB::raw('ST_AsGeoJSON(geom)'));
            if (strlen($geom) > 65536) {
                throw new ApiException('LOCATION_INVALID', 'Checkpoint geometry exceeds the size limit.', [], 422);
            }
            try {
                $spatial = DB::transaction(fn () => DB::selectOne('WITH g AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?),4326) geom)
                    SELECT GeometryType(geom) type, ST_IsValid(geom) AND NOT ST_IsEmpty(geom) AND ST_NDims(geom)=2
                    AND ST_XMin(Box3D(geom)) >= -180 AND ST_XMax(Box3D(geom)) <= 180
                    AND ST_YMin(Box3D(geom)) >= -90 AND ST_YMax(Box3D(geom)) <= 90 AS valid FROM g', [$geom]));
            } catch (QueryException) {
                throw new ApiException('LOCATION_INVALID', 'Invalid checkpoint geometry.', [], 422);
            }
            if (! $spatial->valid || ! in_array($spatial->type, $type === 'POINT' ? ['POINT'] : ['POLYGON', 'MULTIPOLYGON'], true)) {
                throw new ApiException('LOCATION_INVALID', 'Geometry does not match the checkpoint type.', [], 422);
            }
            $checkpoint->forceFill(['organization_id' => $context->organization->id, 'name' => $input['name'] ?? $checkpoint->name,
                'type' => $type, 'radius_meters' => $radius, 'config' => $config, 'metadata' => $input['metadata'] ?? $checkpoint->metadata ?? [], 'status' => $input['status'] ?? $checkpoint->status ?? 'ACTIVE']);
            $checkpoint->geom = DB::raw('ST_SetSRID(ST_GeomFromGeoJSON('.DB::getPdo()->quote($geom).'),4326)');
            $checkpoint->save();
            $this->audit->record($id ? 'checkpoint.updated' : 'checkpoint.created', 'checkpoint', $checkpoint->public_id,
                context: ['status' => $checkpoint->status], actorId: $actor->id, organizationId: $checkpoint->organization_id);

            return $this->project($checkpoint);
        }, 3);
    }

    public function project(Checkpoint $checkpoint): array
    {
        return ['id' => $checkpoint->public_id, 'organization_id' => $checkpoint->organization->public_id, 'name' => $checkpoint->name,
            'type' => $checkpoint->type, 'geom' => json_decode($checkpoint->geojson ?? DB::table('checkpoints')->where('id', $checkpoint->id)->value(DB::raw('ST_AsGeoJSON(geom)')), true),
            'radius_meters' => $checkpoint->radius_meters, 'config' => $checkpoint->config, 'status' => $checkpoint->status];
    }
}

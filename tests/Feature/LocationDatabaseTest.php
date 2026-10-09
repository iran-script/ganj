<?php

namespace Tests\Feature;

use App\Models\Checkpoint;
use App\Services\Locations\GeofenceVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\LocationFixtures;
use Tests\TestCase;

class LocationDatabaseTest extends TestCase
{
    use LocationFixtures, RefreshDatabase;

    public static function spatialCases(): array
    {
        return ['point inside' => ['POINT', 0, 3, 'INSIDE'], 'point outside' => ['POINT', 70, 3, 'OUTSIDE'],
            'point uncertain' => ['POINT', 48, 3, 'UNCERTAIN'], 'point poor' => ['POINT', 0, 40, 'UNCERTAIN'],
            'zone inside' => ['ZONE', 0, 3, 'INSIDE'], 'zone outside' => ['ZONE', 500, 3, 'OUTSIDE']];
    }

    #[DataProvider('spatialCases')]
    public function test_postgis_uses_meter_distances_and_accuracy_aware_geofences(string $type, int $meters, int $accuracy, string $expected): void
    {
        [, $session, , $player, , $checkpoint] = $this->locationFixture();
        if ($type === 'ZONE') {
            $checkpoint->forceFill(['type' => 'ZONE', 'radius_meters' => null,
                'geom' => DB::raw("ST_GeomFromText('POLYGON((51.388 35.6882,51.390 35.6882,51.390 35.6902,51.388 35.6902,51.388 35.6882))',4326)")])->save();
        }
        $point = DB::selectOne('SELECT ST_X(g::geometry) lng,ST_Y(g::geometry) lat FROM (SELECT ST_Project(ST_SetSRID(ST_MakePoint(51.389,35.6892),4326)::geography,?::double precision,0::double precision) g) p', [$meters]);
        $this->ingest($player, $this->sample($session, changes: ['lat' => $point->lat, 'lng' => $point->lng, 'accuracy' => $accuracy]));
        $latest = DB::table('player_latest_locations')->where('game_session_player_id', $player->id)->sole();
        $result = app(GeofenceVerifier::class)->classify($checkpoint, $latest, 30);
        $this->assertSame($expected, $result['result']);
        if ($type === 'POINT') {
            $this->assertEqualsWithDelta($meters, $result['distance_meters'], 0.01);
        }
    }

    public function test_polygon_boundary_is_covered_with_zero_accuracy_but_uncertain_with_uncertainty(): void
    {
        [, $session, , $player] = $this->locationFixture();
        $zone = Checkpoint::factory()->create(['type' => 'ZONE', 'radius_meters' => null,
            'geom' => DB::raw("ST_GeomFromText('POLYGON((51.389 35.6892,51.390 35.6892,51.390 35.6902,51.389 35.6902,51.389 35.6892))',4326)")]);
        $this->ingest($player, $this->sample($session, changes: ['accuracy' => 0]));
        $latest = DB::table('player_latest_locations')->where('game_session_player_id', $player->id)->sole();
        $this->assertSame('INSIDE', app(GeofenceVerifier::class)->classify($zone, $latest, 30)['result']);
        $this->travel(1)->seconds();
        $this->ingest($player, $this->sample($session, 2, ['accuracy' => 1]));
        $latest = DB::table('player_latest_locations')->where('game_session_player_id', $player->id)->sole();
        $this->assertSame('UNCERTAIN', app(GeofenceVerifier::class)->classify($zone, $latest, 30)['result']);
    }

    public function test_spatial_srid_indexes_partition_routing_and_append_only_updates(): void
    {
        [, $session, , $player, , $checkpoint] = $this->locationFixture();
        $this->ingest($player, $this->sample($session));
        $row = DB::selectOne('SELECT ST_SRID(geom) srid,tableoid::regclass::text partition FROM location_events LIMIT 1');
        $this->assertSame(4326, $row->srid);
        $this->assertSame('location_events_'.now()->format('Ym'), $row->partition);
        foreach (['checkpoints_geom_gist', 'location_events_geom_gist', 'location_events_player_received', 'location_events_session_received'] as $index) {
            $this->assertNotNull(DB::table('pg_indexes')->where('indexname', $index)->first());
        }
        try {
            DB::transaction(fn () => DB::table('checkpoints')->where('id', $checkpoint->id)->update(['geom' => DB::raw('ST_SetSRID(ST_MakePoint(1,1),3857)')]));
            $this->fail('Wrong SRID was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('22023', $e->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => DB::table('location_events')->update(['accuracy' => 1]));
            $this->fail('Location history was mutable.');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->errorInfo[0]);
        }
    }

    public function test_point_boundary_is_inclusive_only_when_accuracy_does_not_overlap(): void
    {
        [, $session, , $player, , $checkpoint] = $this->locationFixture();
        $this->ingest($player, $this->sample($session, changes: ['lat' => 35.6893, 'accuracy' => 0]));
        $latest = DB::table('player_latest_locations')->where('game_session_player_id', $player->id)->sole();
        $distance = app(GeofenceVerifier::class)->classify($checkpoint, $latest, 30)['distance_meters'];
        $checkpoint->forceFill(['radius_meters' => $distance])->save();
        $this->assertSame('INSIDE', app(GeofenceVerifier::class)->classify($checkpoint, $latest, 30)['result']);
        $this->travel(1)->seconds();
        $this->ingest($player, $this->sample($session, 2, ['lat' => 35.6893, 'accuracy' => 1]));
        $latest = DB::table('player_latest_locations')->where('game_session_player_id', $player->id)->sole();
        $this->assertSame('UNCERTAIN', app(GeofenceVerifier::class)->classify($checkpoint, $latest, 30)['result']);
    }
}

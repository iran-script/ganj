<?php

namespace Tests\Feature;

use App\Services\Games\SessionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\LocationFixtures;
use Tests\TestCase;

class LocationRetentionTest extends TestCase
{
    use LocationFixtures, RefreshDatabase;

    public function test_retention_purges_expired_raw_history_and_latest_with_bounded_maintenance(): void
    {
        [, $session, , $player] = $this->locationFixture();
        $this->ingest($player, $this->sample($session));
        try {
            DB::transaction(fn () => DB::table('location_events')->delete());
            $this->fail('Normal deletion was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->travel(31)->days();
        $this->artisan('location:maintain-history')->assertExitCode(0);
        $this->assertDatabaseCount('location_events', 0);
        $this->assertDatabaseCount('geofence_evaluations', 0);
        $this->assertDatabaseCount('player_latest_locations', 0);
    }

    public function test_closed_session_route_summary_is_generated_without_runtime_side_effects(): void
    {
        [$admin, $session, , $player] = $this->locationFixture();
        $this->ingest($player, $this->sample($session));
        $this->travel(10)->seconds();
        $this->ingest($player, $this->sample($session, 2, ['lat' => 35.6893]));
        app(SessionService::class)->execute($admin, 'finish', ['reason' => 'route test'], $session->public_id, key: 'location-route-finish');
        $this->artisan('location:maintain-history')->assertExitCode(0);
        $this->artisan('location:maintain-history')->assertExitCode(0);
        $this->assertDatabaseCount('route_summaries', 1);
        $summary = DB::table('route_summaries')->sole();
        $this->assertGreaterThan(10, $summary->distance_meters);
        $this->assertLessThan(12, $summary->distance_meters);
    }
}

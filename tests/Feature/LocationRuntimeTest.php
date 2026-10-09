<?php

namespace Tests\Feature;

use App\Models\PlayerRoleAssignment;
use App\Models\RuntimeEvent;
use App\Services\Locations\RuntimeLocationVerification;
use App\Services\Runtime\LocationVerification;
use App\Services\Runtime\RuntimeEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\LocationFixtures;
use Tests\TestCase;

class LocationRuntimeTest extends TestCase
{
    use LocationFixtures, RefreshDatabase;

    public function test_real_location_adapter_completes_once_and_preserves_runtime_authority(): void
    {
        [, $session, , $player, $node] = $this->locationFixture();
        $this->assertInstanceOf(RuntimeLocationVerification::class, app(LocationVerification::class));
        $this->ingest($player, $this->sample($session));
        $this->assertSame('ACTIVE', $node->fresh()->status);
        $result = $this->runtimeAction($player, $node, 'complete_node', key: 'location-complete-key');
        $this->assertTrue($result['complete']);
        $this->assertSame($result, $this->runtimeAction($player, $node, 'complete_node', key: 'location-complete-key'));
        $this->assertSame('COMPLETED', $node->fresh()->status);
        $this->assertSame(1, RuntimeEvent::where('event_type', 'runtime.node_completed.v1')->where('aggregate_id', $node->public_id)->count());
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'location.checkpoint_verified.v1')->count());
    }

    public static function rejectedEvidence(): array
    {
        return ['outside' => [['lat' => 35.70]], 'uncertain' => [['accuracy' => 90]],
            'offline' => [['client_timestamp' => '2026-10-08T00:00:00Z']]];
    }

    #[DataProvider('rejectedEvidence')]
    public function test_outside_uncertain_or_late_evidence_does_not_complete(array $changes): void
    {
        $this->travelTo(Carbon::parse('2026-10-10T00:00:00Z'));
        [, $session, , $player, $node] = $this->locationFixture();
        $this->ingest($player, $this->sample($session, changes: $changes));
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
        $this->assertSame('ACTIVE', $node->fresh()->status);
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'location.checkpoint_rejected.v1')->count());
    }

    public function test_dwell_requires_repeated_continuous_server_timed_evidence_and_survives_reload(): void
    {
        [, $session, , $player, $node] = $this->locationFixture(['dwell_time' => 10]);
        $this->ingest($player, $this->sample($session));
        $this->travel(10)->seconds();
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
        $this->ingest($player, $this->sample($session, 2));
        $this->app->forgetInstance(LocationVerification::class);
        $this->assertTrue($this->runtimeAction($player, $node->fresh(), 'complete_node')['complete']);
    }

    public static function resets(): array
    {
        return ['exit' => [['lat' => 35.70], 5], 'uncertainty' => [['accuracy' => 90], 5], 'gap' => [[], 20]];
    }

    #[DataProvider('resets')]
    public function test_exit_uncertainty_and_sampling_gap_reset_dwell(array $changes, int $gap): void
    {
        [, $session, , $player, $node] = $this->locationFixture(['dwell_time' => 10]);
        $this->ingest($player, $this->sample($session));
        $this->travel($gap)->seconds();
        $this->ingest($player, $this->sample($session, 2, $changes));
        $this->travel(1)->seconds();
        $this->ingest($player, $this->sample($session, 3));
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
    }

    public function test_player_count_is_distinct_same_team_and_fresh(): void
    {
        [, $session, $instance, $player, $node] = $this->locationFixture(['required_player_count' => 2], 2);
        $other = $instance->registration->players()->whereKeyNot($player->id)->firstOrFail();
        $foreign = $session->players()->where('game_session_team_id', '<>', $instance->game_session_team_id)->firstOrFail();
        $this->ingest($player, $this->sample($session));
        $this->ingest($foreign, $this->sample($session));
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
        $this->ingest($other, $this->sample($session, changes: ['accuracy' => 90]));
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
        $this->travel(31)->seconds();
        $this->ingest($other, $this->sample($session, 2));
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
        $this->ingest($player, $this->sample($session, 2));
        $this->assertTrue($this->runtimeAction($player, $node, 'complete_node')['complete']);
    }

    public function test_required_gameplay_role_and_capability_ignore_captain_and_released_assignments(): void
    {
        [$admin, $session, , $player, $node] = $this->locationFixture(['required_roles' => ['NAVIGATOR'], 'required_capabilities' => ['NAVIGATE']]);
        $this->ingest($player, $this->sample($session));
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
        app(RuntimeEngine::class)->execute($admin, 'assign_role', $node->public_id,
            ['reason' => 'location role', 'player_id' => $player->public_id, 'role_code' => 'NAVIGATOR', 'slot_no' => 1], 'location-role-key');
        $assignment = PlayerRoleAssignment::sole();
        app(RuntimeEngine::class)->execute($admin, 'release_role', $node->public_id,
            ['reason' => 'release', 'assignment_id' => $assignment->public_id], 'location-release-key');
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
        app(RuntimeEngine::class)->execute($admin, 'assign_role', $node->public_id,
            ['reason' => 'location role', 'player_id' => $player->public_id, 'role_code' => 'NAVIGATOR', 'slot_no' => 1], 'location-role-second');
        $this->assertTrue($this->runtimeAction($player, $node, 'complete_node')['complete']);
    }
}

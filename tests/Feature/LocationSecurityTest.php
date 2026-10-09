<?php

namespace Tests\Feature;

use App\Models\Checkpoint;
use App\Services\Games\SessionService;
use App\Services\Locations\CheckpointService;
use App\Services\Locations\LocationService;
use App\Services\Outbox\OutboxService;
use App\Services\Runtime\RuntimeEngine;
use App\Services\Runtime\RuntimeProjection;
use App\Services\Scenarios\GraphValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\LocationFixtures;
use Tests\TestCase;

class LocationSecurityTest extends TestCase
{
    use LocationFixtures, RefreshDatabase;

    public static function excludedStatuses(): array
    {
        return [['REMOVED'], ['DISQUALIFIED'], ['FINISHED']];
    }

    #[DataProvider('excludedStatuses')]
    public function test_ineligible_player_does_not_satisfy_team_presence(string $status): void
    {
        [, $session, $instance, $player, $node] = $this->locationFixture(['required_player_count' => 2]);
        $other = $instance->registration->players()->whereKeyNot($player->id)->firstOrFail();
        $this->ingest($player, $this->sample($session));
        $this->ingest($other, $this->sample($session));
        $other->forceFill(['participation_status' => $status])->save();
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => $this->ingest($other, $this->sample($session, 2)));
    }

    public function test_other_team_device_and_cross_tenant_session_are_rejected(): void
    {
        [, $session, , $player] = $this->locationFixture(teamCount: 2);
        $other = $session->players()->where('game_session_team_id', '<>', $player->game_session_team_id)->firstOrFail();
        $this->assertDomainError('DEVICE_NOT_BOUND', fn () => app(LocationService::class)->ingest($player->user, $other->device->device_uuid, $this->sample($session)));
        [, , $foreign] = $this->sessionFixture();
        $this->assertDomainError('RESOURCE_NOT_FOUND', fn () => $this->ingest($player, $this->sample($foreign)));
    }

    public function test_pause_telemetry_never_satisfies_dwell_after_resume(): void
    {
        [$admin, $session, , $player, $node] = $this->locationFixture(['dwell_time' => 5]);
        $this->ingest($player, $this->sample($session));
        app(SessionService::class)->execute($admin, 'pause', ['reason' => 'location pause'], $session->public_id, key: 'location-pause-key');
        $this->travel(5)->seconds();
        $this->ingest($player, $this->sample($session, 2));
        app(SessionService::class)->execute($admin, 'resume', ['reason' => 'location resume'], $session->public_id, key: 'location-resume-key');
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
        $this->travel(1)->seconds();
        $this->ingest($player, $this->sample($session, 3));
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
        $this->travel(5)->seconds();
        $this->ingest($player, $this->sample($session, 4));
        $this->assertTrue($this->runtimeAction($player, $node, 'complete_node')['complete']);
    }

    public function test_outbox_failure_rolls_back_sample_projection_and_geofence_evidence(): void
    {
        [, $session, , $player] = $this->locationFixture();
        $this->mock(OutboxService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Outbox failure'));
        try {
            $this->ingest($player, $this->sample($session));
            $this->fail('Expected outbox failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Outbox failure', $e->getMessage());
        }
        $this->assertDatabaseCount('location_events', 0);
        $this->assertDatabaseCount('player_latest_locations', 0);
        $this->assertDatabaseCount('geofence_evaluations', 0);
    }

    public function test_configured_accuracy_and_request_correlation_are_respected(): void
    {
        [, $session, , $player, $node] = $this->locationFixture(['min_accuracy' => 2]);
        $this->locationAuth($player);
        $this->withHeader('X-Request-Id', 'location-correlation')->postJson('/api/v1/locations', $this->sample($session))->assertOk();
        $event = DB::table('outbox_events')->where('topic', 'location.geofence_uncertain.v1')->sole();
        $this->assertSame('location-correlation', json_decode($event->payload, true)['request_id']);
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
    }

    public function test_published_checkpoint_policy_is_immutable_and_foreign_reference_fails_validation(): void
    {
        [$admin, , , , , $checkpoint] = $this->locationFixture();
        $this->assertDomainError('CONFLICT', fn () => app(CheckpointService::class)->save($admin, ['radius_meters' => 100], $checkpoint->public_id));
        $foreign = Checkpoint::factory()->create();
        $graph = $this->runtimeGraph('GPS / Location', ['checkpoint_id' => $foreign->public_id, 'min_accuracy' => 10, 'required_player_count' => 1, 'dwell_time' => 0]);
        $this->assertSame('FAIL', app(GraphValidator::class)->validate($graph, $checkpoint->organization_id)['status']);
    }

    public function test_newer_sequence_with_delayed_timestamp_preserves_current_projection(): void
    {
        [, $session, , $player] = $this->locationFixture();
        $this->ingest($player, $this->sample($session));
        $this->ingest($player, $this->sample($session, 5, ['client_timestamp' => now()->subMinutes(5)->toISOString()]));
        $this->assertDatabaseHas('player_latest_locations', ['sequence' => 1]);
        $this->assertDatabaseHas('location_events', ['sequence' => 5, 'is_late' => true]);
    }

    public function test_closed_session_disables_tracking_and_rejects_ingest(): void
    {
        [$admin, $session, , $player] = $this->locationFixture();
        app(SessionService::class)->execute($admin, 'finish', ['reason' => 'finished'], $session->public_id, key: 'location-finish-key');
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => $this->ingest($player, $this->sample($session)));
        $this->assertFalse(app(LocationService::class)->policy($player->user, $session->public_id, $player->device->device_uuid)['active']);
    }

    public function test_revealed_checkpoint_projection_excludes_hidden_source_and_admin_policy(): void
    {
        [, , $instance, , $node, $checkpoint] = $this->locationFixture(['required_roles' => ['NAVIGATOR']]);
        $projection = app(RuntimeProjection::class);
        $data = $projection->instance($instance);
        $task = collect($data['nodes'])->firstWhere('id', $node->public_id);
        $this->assertSame($checkpoint->public_id, $task['config']['checkpoint']['id']);
        $this->assertSame('Point', $task['config']['checkpoint']['geom']['type']);
        $this->assertArrayNotHasKey('config', $task['config']['checkpoint']);
        $this->assertArrayNotHasKey('organization_id', $task['config']['checkpoint']);
        $node->status = 'LOCKED';
        $this->assertArrayNotHasKey('checkpoint', $projection->node($node)['config']);
    }

    public function test_wrong_runtime_role_cannot_complete_a_location_node(): void
    {
        [$admin, $session, , $player, $node] = $this->locationFixture(['required_roles' => ['NAVIGATOR']]);
        $this->ingest($player, $this->sample($session));
        app(RuntimeEngine::class)->execute($admin, 'assign_role', $node->public_id,
            ['reason' => 'wrong role regression', 'player_id' => $player->public_id, 'role_code' => 'DECODER', 'slot_no' => 1], 'location-wrong-role');
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => $this->runtimeAction($player, $node, 'complete_node'));
        $this->assertSame('ACTIVE', $node->fresh()->status);
    }
}

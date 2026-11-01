<?php

namespace Tests\Feature;

use App\Models\Checkpoint;
use App\Models\GameSession;
use App\Models\QrTrigger;
use App\Models\User;
use App\Services\Locations\LocationService;
use App\Services\Qr\QrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class QrProximityTest extends TestCase
{
    use RefreshDatabase, RuntimeFixtures;

    public function test_qr_stage_proximity_requires_fresh_server_evaluated_location_on_active_node(): void
    {
        $this->freezeTime();
        $checkpoint = null;
        $trigger = null;
        [, $session, , $player, $gpsNode] = $this->runtimeFixture(beforePublish: function (GameSession $session) use (&$checkpoint, &$trigger): array {
            $checkpoint = Checkpoint::factory()->create(['organization_id' => $session->organization_id, 'radius_meters' => 20]);
            $trigger = app(QrService::class)->provision($this->currentActorForSession($session), ['organization_id' => $session->organization->public_id]);

            return ['nodes' => [
                ['node_key' => 'start', 'node_type' => 'Start', 'config' => []],
                ['node_key' => 'task', 'node_type' => 'GPS / Location', 'config' => ['checkpoint_id' => $checkpoint->public_id, 'radius_m' => 20]],
                ['node_key' => 'qr', 'node_type' => 'QR', 'config' => ['trigger_id' => $trigger['id'], 'checkpoint_id' => $checkpoint->public_id, 'radius_m' => 20]],
                ['node_key' => 'finish', 'node_type' => 'Finish', 'config' => []],
            ], 'edges' => [
                ['edge_key' => 'start-task', 'from_node' => 'start', 'to_node' => 'task', 'priority' => 0],
                ['edge_key' => 'task-qr', 'from_node' => 'task', 'to_node' => 'qr', 'priority' => 0],
                ['edge_key' => 'qr-finish', 'from_node' => 'qr', 'to_node' => 'finish', 'priority' => 0],
            ], 'variables' => [], 'conditions' => [], 'subflows' => []];
        });

        $qrNode = $gpsNode->instance->nodes()->whereHas('node', fn ($query) => $query->where('node_key', 'qr'))->firstOrFail();
        $this->runtimeAction($player, $gpsNode, 'start_node');
        $location = app(LocationService::class);
        $location->ingest($player->user, $player->device->device_uuid, $this->sample($session, 1));
        $this->assertTrue($this->runtimeAction($player, $gpsNode, 'complete_node')['complete']);
        $this->runtimeAction($player, $qrNode, 'start_node');

        $identifier = QrTrigger::where('public_id', $trigger['id'])->value('printable_identity');
        $this->withHeader('Idempotency-Key', 'unauthenticated-qr-scan')->postJson('/api/v1/qr/scan', [
            'session_id' => $session->public_id, 'qr_identifier' => $identifier,
        ])->assertUnauthorized();
        $this->postJson('/api/v1/qr/proximity', ['session_id' => $session->public_id])->assertUnauthorized();
        $this->authenticateGame($player->user, $player->device);
        $this->withHeader('Idempotency-Key', 'pre-gps-scan')->postJson('/api/v1/qr/scan', [
            'session_id' => $session->public_id, 'qr_identifier' => $identifier,
        ])->assertStatus(409)->assertJsonPath('error.code', 'QR_OUTSIDE_ALLOWED_AREA');
        $this->postJson('/api/v1/qr/proximity', ['session_id' => $session->public_id])
            ->assertStatus(409)->assertJsonPath('error.code', 'QR_OUTSIDE_ALLOWED_AREA');

        $this->travel(1)->seconds();
        $location->ingest($player->user, $player->device->device_uuid, $this->sample($session, 2));
        $this->postJson('/api/v1/qr/proximity', ['session_id' => $session->public_id])
            ->assertOk()->assertJsonPath('data.inside', true)->assertJsonPath('data.accuracy_meters', 3);

        $this->travel(31)->seconds();
        $this->withHeader('Idempotency-Key', 'stale-location-scan')->postJson('/api/v1/qr/scan', [
            'session_id' => $session->public_id, 'qr_identifier' => $identifier,
        ])->assertStatus(409)->assertJsonPath('error.code', 'QR_OUTSIDE_ALLOWED_AREA');

        $location->ingest($player->user, $player->device->device_uuid, $this->sample($session, 3));
        $this->withHeader('Idempotency-Key', 'fresh-location-scan')->postJson('/api/v1/qr/scan', [
            'session_id' => $session->public_id, 'qr_identifier' => $identifier,
        ])->assertOk()->assertJsonPath('data.accepted', true);
    }

    private function currentActorForSession(GameSession $session): User
    {
        return User::findOrFail($session->created_by);
    }

    private function sample(GameSession $session, int $sequence): array
    {
        return ['session_id' => $session->public_id, 'lat' => 35.6892, 'lng' => 51.389,
            'accuracy' => 3, 'client_timestamp' => now()->startOfSecond()->toISOString(), 'sequence' => $sequence];
    }
}

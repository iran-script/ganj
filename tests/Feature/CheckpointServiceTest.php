<?php

namespace Tests\Feature;

use App\Models\Checkpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ScenarioFixtures;
use Tests\TestCase;

class CheckpointServiceTest extends TestCase
{
    use RefreshDatabase, ScenarioFixtures;

    public function test_scoped_admin_creates_and_updates_checkpoint_with_audit(): void
    {
        [$admin, $scenario] = $this->scenarioFixture();
        $this->scenarioAuth($admin);
        $body = ['organization_id' => $scenario->organization->public_id, 'name' => 'Meeting place',
            'type' => 'POINT', 'geom' => ['type' => 'Point', 'coordinates' => [51.389, 35.6892]], 'radius_meters' => 50,
            'config' => ['dwell_time' => 10, 'required_roles' => ['NAVIGATOR']]];
        $result = $this->postJson('/api/v1/checkpoints', $body)->assertCreated()->assertJsonPath('data.name', 'Meeting place');
        $id = $result->json('data.id');
        $this->patchJson('/api/v1/checkpoints/'.$id, ['status' => 'INACTIVE'])->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->getJson('/api/v1/checkpoints')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_events', ['action' => 'checkpoint.created', 'resource_id' => $id]);
        $this->assertSame($scenario->organization->public_id, $result->json('data.organization_id'));
    }

    public function test_player_and_other_tenant_cannot_read_or_mutate_hidden_checkpoint(): void
    {
        [$admin] = $this->scenarioFixture();
        $checkpoint = Checkpoint::factory()->create();
        $this->scenarioAuth($admin);
        $this->getJson('/api/v1/checkpoints/'.$checkpoint->public_id)->assertNotFound();
        $this->patchJson('/api/v1/checkpoints/'.$checkpoint->public_id, ['status' => 'INACTIVE'])->assertNotFound();
        $this->scenarioAuth(User::factory()->create());
        $this->getJson('/api/v1/checkpoints/'.$checkpoint->public_id)->assertNotFound();
        $this->getJson('/api/v1/checkpoints')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_invalid_polygon_is_rejected_without_partial_write(): void
    {
        [$admin, $scenario] = $this->scenarioFixture();
        $this->scenarioAuth($admin);
        $this->postJson('/api/v1/checkpoints', ['organization_id' => $scenario->organization->public_id, 'name' => 'Invalid',
            'type' => 'ZONE', 'geom' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 1], [1, 0], [0, 1], [0, 0]]]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_INVALID');
        $this->assertDatabaseCount('checkpoints', 0);
    }
}

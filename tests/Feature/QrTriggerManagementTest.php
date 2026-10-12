<?php

namespace Tests\Feature;

use App\Models\QrTrigger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ScenarioFixtures;
use Tests\TestCase;

class QrTriggerManagementTest extends TestCase
{
    use RefreshDatabase, ScenarioFixtures;

    public function test_administrator_can_create_list_and_read_qr_trigger_without_secret_leakage(): void
    {
        [$admin, $scenario] = $this->scenarioFixture(graph: false);
        $this->scenarioAuth($admin);
        $created = $this->postJson('/api/v1/qr/triggers', ['organization_id' => $scenario->organization->public_id, 'metadata' => ['label' => 'ورودی شمالی']])
            ->assertCreated()->assertJsonPath('data.trigger.status', 'ACTIVE');
        $identifier = $created->json('data.identifier');
        $id = $created->json('data.trigger.id');

        $this->getJson('/api/v1/qr/triggers')->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissing(['identifier' => $identifier])->assertJsonMissing(['identifier_hash' => hash('sha256', $identifier)]);
        $this->getJson('/api/v1/qr/triggers/'.$id)->assertOk()->assertJsonPath('data.id', $id)
            ->assertJsonMissing(['identifier' => $identifier]);
    }

    public function test_trigger_management_is_tenant_scoped_and_lifecycle_status_is_visible(): void
    {
        [$admin, $scenario] = $this->scenarioFixture(graph: false);
        $trigger = QrTrigger::factory()->create(['organization_id' => $scenario->organization_id]);
        $this->scenarioAuth($admin);
        $this->postJson('/api/v1/qr/triggers/'.$trigger->public_id.'/deactivate')->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->getJson('/api/v1/qr/triggers/'.$trigger->public_id)->assertOk()->assertJsonPath('data.status', 'INACTIVE');

        $this->scenarioAuth(User::factory()->create());
        $this->getJson('/api/v1/qr/triggers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/qr/triggers/'.$trigger->public_id)->assertNotFound();
        $this->postJson('/api/v1/qr/triggers/'.$trigger->public_id.'/activate')->assertNotFound();
    }
}

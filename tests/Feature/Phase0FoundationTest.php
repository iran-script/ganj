<?php

namespace Tests\Feature;

use App\Services\Idempotency\DatabaseIdempotencyStore;
use App\Services\Outbox\OutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase0FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_contract_and_request_id(): void
    {
        $response = $this->getJson('/api/v1/health');
        $response->assertOk()->assertJsonStructure(['data' => ['status', 'checks']]);
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_postgis_query(): void
    {
        $this->assertNotEmpty(DB::selectOne('select PostGIS_Version()')->postgis_version);
    }

    public function test_idempotency_and_outbox(): void
    {
        $store = app(DatabaseIdempotencyStore::class);
        $store->reserve('test-key', hash('sha256', 'request'));
        $store->complete('test-key', hash('sha256', 'request'), 201, ['data' => ['ok' => true]]);
        $this->assertSame('completed', $store->reserve('test-key', hash('sha256', 'request'))['status']);
        $eventId = app(OutboxService::class)->record('test.topic', 'test', 'test', '1', ['ok' => true]);
        $this->assertDatabaseHas('outbox_events', ['event_id' => $eventId]);
    }
}

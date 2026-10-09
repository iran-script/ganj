<?php

namespace Tests\Feature;

use App\Models\UserDevice;
use App\Services\Locations\LocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\LocationFixtures;
use Tests\TestCase;

class LocationServiceTest extends TestCase
{
    use LocationFixtures, RefreshDatabase;

    public function test_ingest_is_sequence_ordered_and_retries_are_idempotent(): void
    {
        [, $session, , $player] = $this->locationFixture();
        $sample = $this->sample($session, 2);
        $first = $this->ingest($player, $sample);
        $this->assertSame($first, $this->ingest($player, $sample));
        $this->assertDatabaseCount('location_events', 1);
        $this->ingest($player, $this->sample($session, 1, ['client_timestamp' => now()->subMinutes(2)->toISOString()]));
        $this->assertDatabaseHas('location_events', ['sequence' => 1, 'is_late' => true]);
        $this->assertDatabaseHas('player_latest_locations', ['sequence' => 2]);
        $this->assertDomainError('IDEMPOTENCY_CONFLICT', fn () => $this->ingest($player, array_replace($sample, ['accuracy' => 4])));
        $this->travel(2)->seconds();
        $this->ingest($player, $this->sample($session, 3));
        $this->assertDatabaseHas('player_latest_locations', ['sequence' => 3]);
    }

    public function test_offline_batch_is_sorted_atomic_and_duplicate_safe(): void
    {
        [, $session, , $player] = $this->locationFixture();
        $samples = [$this->sample($session, 3), $this->sample($session, 1, ['client_timestamp' => now()->subMinutes(3)->toISOString()])];
        $service = app(LocationService::class);
        $first = $service->batch($player->user, $player->device->device_uuid, ['samples' => $samples]);
        $this->assertSame($first, $service->batch($player->user, $player->device->device_uuid, ['samples' => $samples]));
        $this->assertSame([1, 3], DB::table('location_events')->orderBy('id')->pluck('sequence')->all());
        $this->assertDatabaseCount('location_events', 2);
        $this->assertDomainError('IDEMPOTENCY_CONFLICT', fn () => $service->batch($player->user, $player->device->device_uuid,
            ['samples' => [$this->sample($session, 2), $this->sample($session, 3, ['accuracy' => 6])]]));
        $this->assertDatabaseCount('location_events', 2);
    }

    public static function invalidSamples(): array
    {
        return ['latitude' => [['lat' => 91]], 'longitude' => [['lng' => -181]], 'accuracy' => [['accuracy' => -1]],
            'sequence' => [['sequence' => -1]], 'truth' => [['inside' => true]], 'organization' => [['organization_id' => 'injected']],
            'player' => [['player_id' => 'spoofed']], 'time' => [['client_timestamp' => 'yesterday']]];
    }

    #[DataProvider('invalidSamples')]
    public function test_returns_422_for_malformed_or_authority_injecting_observations(array $changes): void
    {
        [, $session, , $player] = $this->locationFixture();
        $this->locationAuth($player);
        $this->postJson('/api/v1/locations', $this->sample($session, changes: $changes))->assertStatus(422);
        $this->assertDatabaseCount('location_events', 0);
    }

    public function test_future_timestamp_returns_422_and_wrong_device_returns_403(): void
    {
        [, $session, , $player] = $this->locationFixture();
        $this->locationAuth($player);
        $this->postJson('/api/v1/locations', $this->sample($session, changes: ['client_timestamp' => now()->addMinutes(5)->toISOString()]))
            ->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_INVALID');
        $other = UserDevice::factory()->create(['user_id' => $player->user_id]);
        $this->withHeader('X-Device-Id', $other->device_uuid)->postJson('/api/v1/locations', $this->sample($session))
            ->assertStatus(403)->assertJsonPath('error.code', 'DEVICE_NOT_BOUND');
        $other->forceFill(['revoked_at' => now()])->save();
        $this->assertDomainError('DEVICE_NOT_BOUND', fn () => app(LocationService::class)->ingest($player->user, $other->device_uuid, $this->sample($session)));
    }

    public function test_response_exposes_no_internal_ids_or_raw_other_player_data(): void
    {
        $this->freezeTime();
        [, $session, , $player] = $this->locationFixture();
        $this->locationAuth($player);
        $this->postJson('/api/v1/locations', $this->sample($session))->assertOk()->assertExactJson(['data' => [
            'accepted' => true, 'server_received_at' => now()->startOfSecond()->toISOString()]]);
        $this->getJson('/api/v1/game-sessions/'.$session->public_id.'/players/me/location-policy')->assertOk()->assertJsonPath('data.active', true);
        $this->getJson('/api/v1/locations')->assertStatus(404);
    }
}

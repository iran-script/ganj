<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Services\Identity\IdentityService;
use App\Services\Locations\LocationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\LocationFixtures;
use Tests\TestCase;

class LocationConcurrencyTest extends TestCase
{
    use DatabaseMigrations, LocationFixtures;

    public static function races(): array
    {
        return [['duplicate'], ['conflict'], ['revoke'], ['completion'], ['batch'], ['final arrival']];
    }

    #[DataProvider('races')]
    public function test_contenders_wait_and_recheck_location_guards(string $race): void
    {
        [, $session, $instance, $player, $node] = $this->locationFixture(['required_player_count' => $race === 'final arrival' ? 2 : 1]);
        $sample = $this->sample($session);
        $one = fn () => $this->ingest($player, $sample);
        $two = $one;
        $expected = null;
        if ($race === 'conflict') {
            $two = fn () => $this->ingest($player, array_replace($sample, ['accuracy' => 4]));
            $expected = 'IDEMPOTENCY_CONFLICT';
        } elseif ($race === 'revoke') {
            $one = fn () => app(IdentityService::class)->revoke($player->user, $player->device->public_id);
            $expected = 'DEVICE_NOT_BOUND';
        } elseif ($race === 'completion') {
            $this->ingest($player, $sample);
            $one = fn () => $this->runtimeAction($player, $node, 'complete_node', key: 'location-concurrent-complete');
            $two = $one;
        } elseif ($race === 'final arrival') {
            $other = $instance->registration->players()->whereKeyNot($player->id)->firstOrFail();
            $this->ingest($player, $sample);
            $one = fn () => DB::transaction(function () use ($other, $sample, $node): void {
                $this->ingest($other, $sample);
                $this->runtimeAction($other, $node, 'complete_node', key: 'location-final-arrival');
            });
            $two = fn () => $this->runtimeAction($player, $node, 'complete_node', key: 'location-other-arrival');
            $expected = 'STATE_TRANSITION_INVALID';
        } elseif ($race === 'batch') {
            $one = fn () => app(LocationService::class)->batch($player->user, $player->device->device_uuid, ['samples' => [$sample]]);
            $two = $one;
        }
        $default = DB::getDefaultConnection();
        config(['database.connections.location_probe' => config('database.connections.'.$default)]);
        $primary = DB::connection($default);
        DB::connection('location_probe')->statement("SET lock_timeout = '200ms'");
        $primary->beginTransaction();
        try {
            $one();
            DB::setDefaultConnection('location_probe');
            try {
                $two();
                $this->fail('Location contender bypassed the database lock.');
            } catch (QueryException $e) {
                $this->assertSame('55P03', $e->errorInfo[0]);
            }
            DB::setDefaultConnection($default);
            $primary->commit();
            DB::setDefaultConnection('location_probe');
            try {
                $two();
                $this->assertNull($expected);
            } catch (ApiException $e) {
                $this->assertSame($expected, $e->errorCode);
            }
        } finally {
            DB::setDefaultConnection($default);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('location_probe');
        }
        $this->assertDatabaseCount('location_events', $race === 'revoke' ? 0 : ($race === 'final arrival' ? 2 : 1));
        if (in_array($race, ['completion', 'final arrival'], true)) {
            $this->assertSame(1, DB::table('outbox_events')->where('topic', 'location.checkpoint_verified.v1')->count());
        }
    }
}

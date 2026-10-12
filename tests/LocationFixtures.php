<?php

namespace Tests;

use App\Models\Checkpoint;
use App\Models\GameSession;
use App\Models\GameSessionPlayer;
use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Services\Games\RuntimeBootstrap;
use App\Services\Games\ScenarioAssignmentReadiness;
use App\Services\Games\SessionService;
use App\Services\Locations\LocationService;
use App\Services\Runtime\RuntimeInitializer;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Support\Str;

trait LocationFixtures
{
    use RuntimeFixtures;

    protected function locationFixture(array $policy = [], int $teamCount = 1, ?array $gpsConfig = null): array
    {
        $this->adapters();
        [$admin, , $session] = $this->sessionFixture();
        for ($i = 0; $i < $teamCount; $i++) {
            $this->registerFixture($session, 4);
        }
        $this->readyFixture($session, 4);
        $checkpoint = Checkpoint::factory()->create(['organization_id' => $session->organization_id, 'config' => $policy]);
        $scenario = Scenario::factory()->create(['organization_id' => $session->organization_id, 'created_by' => $admin->id]);
        $version = ScenarioVersion::factory()->create(['scenario_id' => $scenario->id]);
        $graph = $gpsConfig !== null ? $this->runtimeGraph('GPS / Location', $gpsConfig) : $this->runtimeGraph('Checkpoint', ['checkpoint_id' => $checkpoint->public_id]);
        app(ScenarioService::class)->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->revision_token], $version->public_id);
        $this->publishFixture($admin, $version->fresh());
        $this->app->instance(ScenarioAssignmentReadiness::class, new class($version->public_id) implements ScenarioAssignmentReadiness
        {
            public function __construct(private string $version) {}

            public function assignments(GameSession $session, array $roster): array
            {
                return array_fill_keys(array_column($roster, 'team_id'), $this->version);
            }
        });
        $this->app->forgetInstance(RuntimeBootstrap::class);
        $this->app->bind(RuntimeBootstrap::class, RuntimeInitializer::class);
        app(SessionService::class)->execute($admin, 'closeRegistration', [], $session->public_id);
        app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'location-start-'.$session->public_id);
        $instance = TeamScenarioInstance::where('game_session_id', $session->id)->orderBy('id')->firstOrFail();
        $node = TeamNodeInstance::where('team_scenario_instance_id', $instance->id)->whereHas('node', fn ($q) => $q->where('node_key', 'task'))->sole();
        $player = $instance->registration->players()->orderBy('id')->firstOrFail();
        $this->runtimeAction($player, $node, 'start_node');

        return [$admin, $session->fresh(), $instance, $player, $node, $checkpoint];
    }

    protected function sample(GameSession $session, int $sequence = 1, array $changes = []): array
    {
        return array_replace(['session_id' => $session->public_id, 'lat' => 35.6892, 'lng' => 51.389,
            'accuracy' => 3, 'client_timestamp' => now()->startOfSecond()->toISOString(), 'sequence' => $sequence], $changes);
    }

    protected function locationAuth(GameSessionPlayer $player): void
    {
        $token = Str::random(64);
        $access = $player->user->tokens()->make(['name' => 'location-test', 'token' => hash('sha256', $token), 'abilities' => ['*']]);
        $access->forceFill(['user_device_id' => $player->active_device_id])->save();
        $this->withToken($token)->withHeader('X-Device-Id', $player->device->device_uuid);
    }

    protected function ingest(GameSessionPlayer $player, array $sample): array
    {
        return app(LocationService::class)->ingest($player->user, $player->device->device_uuid, $sample);
    }
}

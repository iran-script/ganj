<?php

namespace Tests;

use App\Models\GameSession;
use App\Models\GameSessionPlayer;
use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Services\Games\RuntimeBootstrap;
use App\Services\Games\ScenarioAssignmentReadiness;
use App\Services\Games\SessionService;
use App\Services\Runtime\RuntimeEngine;
use App\Services\Runtime\RuntimeInitializer;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Support\Str;

trait RuntimeFixtures
{
    use GameSessionFixtures, ScenarioFixtures;

    protected function runtimeGraph(string $type = 'Password', ?array $config = null): array
    {
        $config ??= ['prompt' => 'Enter code', 'expected_answer' => 'Secret', 'case_sensitive' => true, 'trim_whitespace' => true];

        return ['nodes' => [['node_key' => 'start', 'node_type' => 'Start', 'config' => []],
            ['node_key' => 'task', 'node_type' => $type, 'config' => $config], ['node_key' => 'finish', 'node_type' => 'Finish', 'config' => []]],
            'edges' => [['edge_key' => 'enter', 'from_node' => 'start', 'to_node' => 'task', 'priority' => 0],
                ['edge_key' => 'exit', 'from_node' => 'task', 'to_node' => 'finish', 'priority' => 0]], 'variables' => [], 'conditions' => [], 'subflows' => []];
    }

    protected function runtimeFixture(?array $graph = null, int $teamCount = 1, int $playerCount = 4): array
    {
        $this->adapters();
        [$admin, , $session] = $this->sessionFixture();
        for ($i = 0; $i < $teamCount; $i++) {
            $this->registerFixture($session, $playerCount);
        }
        $this->readyFixture($session, $playerCount);
        $scenario = Scenario::factory()->create(['organization_id' => $session->organization_id, 'created_by' => $admin->id]);
        $version = ScenarioVersion::factory()->create(['scenario_id' => $scenario->id]);
        app(ScenarioService::class)->execute($admin, 'saveGraph', ($graph ?? $this->runtimeGraph()) + ['revision_token' => $version->revision_token], $version->public_id);
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
        app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'runtime-start-'.$session->public_id);
        $instance = TeamScenarioInstance::where('game_session_id', $session->id)->orderBy('id')->firstOrFail();
        $node = TeamNodeInstance::where('team_scenario_instance_id', $instance->id)->whereHas('node', fn ($q) => $q->where('node_key', 'task'))->firstOrFail();
        $player = $instance->registration->players()->orderBy('id')->firstOrFail();

        return [$admin, $session->fresh(), $instance, $player, $node, $version->fresh()];
    }

    protected function runtimeAction(GameSessionPlayer $player, TeamNodeInstance $node, string $action, array $input = [], ?string $key = null): array
    {
        return app(RuntimeEngine::class)->execute($player->user, $action, $node->public_id, $input, $key ?? (string) Str::uuid(), $player->device);
    }
}

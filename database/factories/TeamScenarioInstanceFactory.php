<?php

namespace Database\Factories;

use App\Models\GameSessionTeam;
use App\Models\OrganizationMember;
use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Models\TeamScenarioInstance;
use App\Models\User;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeamScenarioInstance> */
class TeamScenarioInstanceFactory extends Factory
{
    public function definition(): array
    {
        return ['game_session_team_id' => GameSessionTeam::factory(),
            'game_session_id' => fn (array $a): int => GameSessionTeam::findOrFail($a['game_session_team_id'])->game_session_id,
            'scenario_version_id' => function (array $a): int {
                $organizationId = GameSessionTeam::findOrFail($a['game_session_team_id'])->session->organization_id;
                $admin = User::factory()->create();
                OrganizationMember::factory()->administrator()->create(['organization_id' => $organizationId, 'user_id' => $admin->id]);
                $scenario = Scenario::factory()->create(['organization_id' => $organizationId, 'created_by' => $admin->id]);
                $version = ScenarioVersion::factory()->create(['scenario_id' => $scenario->id]);
                $service = app(ScenarioService::class);
                $service->execute($admin, 'saveGraph', ['revision_token' => $version->revision_token,
                    'nodes' => [['node_key' => 'start', 'node_type' => 'Start', 'config' => []], ['node_key' => 'finish', 'node_type' => 'Finish', 'config' => []]],
                    'edges' => [['edge_key' => 'next', 'from_node' => 'start', 'to_node' => 'finish', 'priority' => 0]]], $version->public_id);
                $service->execute($admin, 'validate', [], $version->public_id);
                $service->execute($admin, 'publish', ['revision_token' => $version->fresh()->revision_token], $version->public_id);

                return $version->id;
            },
            'status' => 'NOT_STARTED', 'random_seed' => bin2hex(random_bytes(32)), 'pause_sources' => [], 'effective_elapsed_seconds' => 0];
    }
}

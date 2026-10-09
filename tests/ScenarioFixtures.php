<?php

namespace Tests;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Support\Str;

trait ScenarioFixtures
{
    protected function scenarioFixture(bool $graph = true): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        OrganizationMember::factory()->administrator()->create(['organization_id' => $organization->id, 'user_id' => $admin->id]);
        $scenario = Scenario::factory()->create(['organization_id' => $organization->id, 'created_by' => $admin->id]);
        $version = ScenarioVersion::factory()->create(['scenario_id' => $scenario->id]);
        if ($graph) {
            app(ScenarioService::class)->execute($admin, 'saveGraph', $this->simpleGraph() + ['revision_token' => $version->revision_token], $version->public_id);
        }

        return [$admin, $scenario, $version->fresh()];
    }

    protected function simpleGraph(): array
    {
        return ['nodes' => [['node_key' => 'start', 'node_type' => 'Start', 'config' => []], ['node_key' => 'finish', 'node_type' => 'Finish', 'config' => []]],
            'edges' => [['edge_key' => 'go', 'from_node' => 'start', 'to_node' => 'finish', 'priority' => 0]], 'variables' => [], 'conditions' => [], 'subflows' => []];
    }

    protected function scenarioAuth(User $user): void
    {
        $device = UserDevice::factory()->create(['user_id' => $user->id]);
        $token = Str::random(64);
        $access = $user->tokens()->make(['name' => 'scenario-test', 'token' => hash('sha256', $token), 'abilities' => ['*']]);
        $access->forceFill(['user_device_id' => $device->id])->save();
        $this->withToken($token)->withHeader('X-Device-Id', $device->device_uuid);
    }

    protected function publishFixture(User $admin, ScenarioVersion $version): array
    {
        $service = app(ScenarioService::class);
        $service->execute($admin, 'validate', [], $version->public_id);

        return $service->execute($admin, 'publish', ['revision_token' => $version->fresh()->revision_token], $version->public_id);
    }
}

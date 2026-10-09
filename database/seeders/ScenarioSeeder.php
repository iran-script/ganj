<?php

namespace Database\Seeders;

use App\Models\OrganizationMember;
use App\Models\Scenario;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Database\Seeder;

class ScenarioSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $member = OrganizationMember::where('role', 'ORGANIZATION_ADMIN')->where('status', 'ACTIVE')->first();
        if (! $member || Scenario::where('organization_id', $member->organization_id)->where('title', 'Scenario authoring example')->exists()) {
            return;
        }
        $service = app(ScenarioService::class);
        $scenario = $service->execute($member->user, 'create', ['organization_id' => $member->organization->public_id, 'title' => 'Scenario authoring example']);
        $version = $service->execute($member->user, 'createVersion', [], $scenario['id']);
        $service->execute($member->user, 'saveGraph', ['revision_token' => $version['revision_token'],
            'nodes' => [['node_key' => 'start', 'node_type' => 'Start', 'config' => []], ['node_key' => 'finish', 'node_type' => 'Finish', 'config' => []]],
            'edges' => [['edge_key' => 'go', 'from_node' => 'start', 'to_node' => 'finish', 'priority' => 0]]], $version['id']);
    }
}

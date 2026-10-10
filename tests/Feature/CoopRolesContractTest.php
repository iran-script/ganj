<?php

namespace Tests\Feature;

use App\Services\Runtime\RoleAssignmentPolicy;
use App\Services\Scenarios\NodeTypeRegistry;
use Tests\TestCase;

class CoopRolesContractTest extends TestCase
{
    public function test_coop_runtime_uses_canonical_role_persistence_and_policies(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_10_08_234715_create_runtime_engine_tables.php'));
        $roles = file_get_contents(base_path('app/Services/Runtime/RuntimeRoles.php'));
        $engine = file_get_contents(base_path('app/Services/Runtime/RuntimeEngine.php'));

        $this->assertStringContainsString("Schema::create('player_role_assignments'", $migration);
        $this->assertStringNotContainsString('runtime_role_assignments', $migration);
        foreach (['SCAN_QR', 'NAVIGATE', 'DECODE', 'OPERATE_AR', 'roleFor', 'assertSubgroupEligible'] as $required) {
            $this->assertStringContainsString($required, $roles);
        }
        foreach (['RuntimeSubgroups', 'lockForUpdate', 'IDEMPOTENCY_CONFLICT'] as $required) {
            $this->assertStringContainsString($required, $engine);
        }
    }

    public function test_coop_node_contract_contains_barrier_relay_and_split_support(): void
    {
        $definitions = app(NodeTypeRegistry::class)->definitions();
        $this->assertArrayHasKey('Team Together', $definitions);
        $this->assertArrayHasKey('Split Team', $definitions);
        $this->assertArrayHasKey('Relay', $definitions);
        $this->assertSame('relay', $definitions['Relay']['runtime_handler']);
        $this->assertContains('SCENARIO_FIXED', RoleAssignmentPolicy::SOURCES);
        $this->assertContains('LATE_JOIN_POLICY', RoleAssignmentPolicy::SOURCES);
    }

    public function test_role_specific_projection_and_capability_boundaries_are_present(): void
    {
        $projection = file_get_contents(base_path('app/Services/Runtime/RuntimeProjectionPolicy.php'));
        $support = file_get_contents(base_path('app/Services/Runtime/SupportParticipationPolicy.php'));
        $nodeRegistry = file_get_contents(base_path('app/Services/Scenarios/NodeTypeRegistry.php'));

        foreach (['role_content', 'subgroup_member'] as $required) {
            $this->assertStringContainsString($required, $projection);
        }
        foreach (['support_policy', 'subgroup_member_ids', 'supports'] as $required) {
            $this->assertStringContainsString($required, $support);
        }
        $this->assertStringContainsString('required_device_capability', $nodeRegistry);
    }

    public function test_coop_documentation_declares_home_verification_pending(): void
    {
        $docs = file_get_contents(base_path('docs/PHASE_2_2.md')).file_get_contents(base_path('docs/COOP_ROLES_TEAM_MECHANICS.md'));

        foreach (['HOME VERIFICATION PENDING', 'Phase 2.1 final verification first', 'PostgreSQL', 'Docker build', 'Queue smoke'] as $required) {
            $this->assertStringContainsString($required, $docs);
        }
    }
}

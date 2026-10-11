<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Organizations\OrganizationScope;
use App\Services\Organizations\OrganizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\GameSessionFixtures;
use Tests\TestCase;

class OrganizationOperatorTest extends TestCase
{
    use GameSessionFixtures, RefreshDatabase;

    public function test_explicit_cli_grant_is_idempotent_and_confers_only_own_organization_administration(): void
    {
        $user = User::factory()->create(['mobile' => '09120000002']);
        $other = Organization::factory()->create();
        $this->assertFalse(Gate::forUser($user)->allows('create', Organization::class));
        $this->artisan('nexo:operator:create', ['mobile' => $user->mobile, '--reason' => 'Authorized operator'])->assertSuccessful();
        $this->artisan('nexo:operator:create', ['mobile' => $user->mobile, '--reason' => 'Repeat grant'])->assertSuccessful();

        $organization = app(OrganizationService::class)->create($user, ['name' => 'Operator organization', 'slug' => 'operator-organization']);

        $this->assertDatabaseCount('role_assignments', 1);
        $this->assertDatabaseHas('role_assignments', ['user_id' => $user->id, 'role_code' => 'ORGANIZATION_OPERATOR']);
        $this->assertFalse(app(OrganizationScope::class)->isSuperAdmin($user));
        $this->assertTrue(Gate::forUser($user)->allows('update', $organization));
        $this->assertFalse(Gate::forUser($user)->allows('update', $other));
        $this->assertFalse(Gate::forUser($user)->allows('archive', $organization));
        $this->assertFalse(Gate::forUser($user)->allows('roles.manageGlobal'));
        $this->assertDatabaseHas('audit_events', ['action' => 'role_assignment.granted']);
    }

    public function test_unprivileged_http_creation_is_forbidden_without_writes(): void
    {
        $this->authenticateGame();

        $this->postJson('/api/v1/organizations', ['name' => 'Denied', 'slug' => 'denied'])->assertForbidden();

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_bootstrap_requires_existing_user_and_reason(): void
    {
        $this->artisan('nexo:operator:create', ['mobile' => 'invalid', '--reason' => 'Explicit'])->assertFailed();
        $this->artisan('nexo:operator:create', ['mobile' => '09120000002', '--reason' => 'Explicit'])->assertFailed();
        $user = User::factory()->create(['mobile' => '09120000002']);
        $this->artisan('nexo:operator:create', ['mobile' => $user->mobile])->assertFailed();
        $this->assertDatabaseCount('role_assignments', 0);
    }

    public function test_revoked_operator_cannot_create_an_organization(): void
    {
        $user = User::factory()->create(['mobile' => '09120000002']);
        $this->artisan('nexo:operator:create', ['mobile' => $user->mobile, '--reason' => 'Explicit'])->assertSuccessful();
        RoleAssignment::sole()->forceFill(['revoked_at' => now()])->save();

        $this->assertFalse(Gate::forUser($user)->allows('create', Organization::class));
    }
}

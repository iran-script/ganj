<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Audit\AuditService;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAuditLogApiTest extends TestCase
{
    public function test_audit_log_requires_super_admin_access(): void
    {
        $this->getJson('/api/v1/admin/audit-events')->assertUnauthorized();

        $organization = Organization::factory()->create();
        $orgAdmin = OrganizationMember::factory()->administrator()->create(['organization_id' => $organization->id])->user;
        $this->getJson('/api/v1/admin/audit-events', $this->authHeaders($orgAdmin))->assertForbidden();

        $superAdmin = $this->superAdmin();
        $this->getJson('/api/v1/admin/audit-events', $this->authHeaders($superAdmin))->assertOk();
    }

    public function test_audit_log_filters_paginates_and_omits_sensitive_fields(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create();
        $audit = app(AuditService::class);
        $audit->record('user.password_reset', 'user', $target->public_id, 'private operator reason', [
            'password' => 'sensitive-password', 'token' => 'sensitive-token', 'safe' => 'must-not-be-returned',
        ], $admin->id);
        $audit->record('organization.member_added.v1', 'organization_member', 'member-1', null, [], $admin->id);

        $response = $this->getJson('/api/v1/admin/audit-events?action=user.password_reset&limit=1', $this->authHeaders($admin))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.page', 1)
            ->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.action', 'user.password_reset')
            ->assertJsonPath('data.0.actor_id', $admin->public_id);

        $this->assertSame([
            'actor_type', 'actor_id', 'action', 'resource_type', 'resource_id', 'organization_id', 'occurred_at',
        ], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString('private operator reason', $response->getContent());
        $this->assertStringNotContainsString('sensitive-password', $response->getContent());
        $this->assertStringNotContainsString('sensitive-token', $response->getContent());
        $this->assertStringNotContainsString('must-not-be-returned', $response->getContent());

        $this->getJson('/api/v1/admin/audit-events?q=organization_member&page=1', $this->authHeaders($admin))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.resource_type', 'organization_member');
        $this->getJson('/api/v1/admin/audit-events?from=2026-10-01&to=2026-10-02', $this->authHeaders($admin))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        RoleAssignment::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    /** @return array<string, string> */
    private function authHeaders(User $user): array
    {
        $device = UserDevice::factory()->create(['user_id' => $user->id, 'platform' => 'web']);
        $plain = Str::random(64);
        $token = $user->tokens()->make(['name' => 'audit-test', 'token' => hash('sha256', $plain), 'abilities' => ['control-center']]);
        $token->forceFill(['user_device_id' => $device->id])->save();

        return ['Authorization' => 'Bearer '.$plain];
    }
}

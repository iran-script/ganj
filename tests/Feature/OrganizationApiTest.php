<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $device = UserDevice::factory()->create(['user_id' => $user->id]);
        $token = Str::random(64);
        $accessToken = $user->tokens()->make(['name' => 'test', 'token' => hash('sha256', $token), 'abilities' => ['*']]);
        $accessToken->forceFill(['user_device_id' => $device->id])->save();
        $this->withToken($token);

        return $user;
    }

    private function administrator(Organization $organization): User
    {
        $user = $this->authenticate();
        OrganizationMember::factory()->administrator()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);

        return $user;
    }

    public function test_organization_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/organizations')->assertUnauthorized()->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
    }

    public function test_super_admin_creates_organization_settings_and_correlated_events(): void
    {
        $actor = $this->authenticate();
        RoleAssignment::factory()->create(['user_id' => $actor->id]);
        $this->travelTo(now()->startOfSecond());

        $response = $this->withHeader('X-Request-Id', 'organization-create')->postJson('/api/v1/organizations', ['name' => 'NEXO Organizer', 'slug' => 'nexo-organizer', 'timezone_default' => 'Asia/Tehran'])->assertCreated()->assertHeader('X-Request-Id', 'organization-create');

        $organization = Organization::sole();
        $this->assertSame($organization->public_id, $response->json('data.id'));
        $this->assertTrue(Str::isUuid($response->json('data.id')));
        $response->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.created_at', now()->toISOString())->assertJsonMissingPath('data.organization_id');
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $organization->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'organization.created.v1', 'actor_id' => $actor->id, 'organization_id' => $organization->id, 'request_id' => 'organization-create']);
        $event = DB::table('outbox_events')->sole();
        $envelope = json_decode($event->payload, true);
        $this->assertSame('organization.created.v1', $event->topic);
        $this->assertSame($organization->public_id, $envelope['organization_id']);
        $this->assertSame($actor->public_id, $envelope['actor']['id']);
        $this->assertSame('organization-create', $envelope['request_id']);
        $this->assertSame($event->event_id, $envelope['event_id']);
        $this->assertSame('pending', $event->status);
    }

    public function test_super_admin_lists_all_organizations_and_reads_global_resources(): void
    {
        $actor = $this->authenticate();
        RoleAssignment::factory()->create(['user_id' => $actor->id]);
        $organizations = Organization::factory()->count(2)->create();

        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/organizations/'.$organizations[1]->public_id)->assertOk()->assertJsonPath('data.id', $organizations[1]->public_id);
    }

    public function test_admin_reads_and_updates_only_permitted_organization_fields(): void
    {
        $organization = Organization::factory()->create();
        $this->administrator($organization);

        $this->getJson('/api/v1/organizations/'.$organization->public_id)->assertOk()->assertJsonPath('data.id', $organization->public_id);
        $this->patchJson('/api/v1/organizations/'.$organization->public_id, ['name' => 'Updated', 'timezone_default' => 'Asia/Tehran'])->assertOk()->assertJsonPath('data.name', 'Updated');

        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'name' => 'Updated', 'timezone_default' => 'Asia/Tehran']);
        $this->assertDatabaseHas('outbox_events', ['topic' => 'organization.updated.v1', 'aggregate_id' => $organization->public_id]);
    }

    public static function tenantRoutes(): array
    {
        return ['read' => ['GET', ''], 'update' => ['PATCH', ''], 'members' => ['GET', '/members'], 'add member' => ['POST', '/members'], 'remove member' => ['DELETE', '/members/target']];
    }

    #[DataProvider('tenantRoutes')]
    public function test_cross_tenant_requests_return_404_without_side_effects(string $method, string $suffix): void
    {
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->administrator($own);
        $target = OrganizationMember::factory()->administrator()->create(['organization_id' => $other->id]);
        $suffix = str_replace('target', $target->user->public_id, $suffix);
        $payload = $method === 'PATCH' ? ['name' => 'Stolen'] : ($method === 'POST' ? ['user_id' => $target->user->public_id, 'role' => 'ORGANIZATION_ADMIN'] : []);

        $this->json($method, '/api/v1/organizations/'.$other->public_id.$suffix, $payload)->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND')->assertJsonStructure(['error' => ['code', 'message', 'details']]);

        $this->assertSame($other->name, $other->fresh()->name);
        $this->assertDatabaseHas('organization_members', ['id' => $target->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_paginated_lists_never_leak_other_organizations_or_internal_ids(): void
    {
        $own = Organization::factory()->count(2)->create();
        $actor = $this->administrator($own[0]);
        OrganizationMember::factory()->administrator()->create(['organization_id' => $own[1]->id, 'user_id' => $actor->id]);
        Organization::factory()->count(3)->create();

        $page = $this->getJson('/api/v1/organizations?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.has_more', true);
        $cursor = $page->json('meta.pagination.next_cursor');
        $decoded = json_decode(base64_decode(strtr($cursor, '-_', '+/')), true);
        $this->assertTrue(Str::isUuid($decoded['public_id']));
        $next = $this->getJson('/api/v1/organizations?limit=1&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.has_more', false);
        $this->assertEqualsCanonicalizing($own->pluck('public_id')->all(), [$page->json('data.0.id'), $next->json('data.0.id')]);
    }

    public function test_member_lists_are_tenant_scoped_paginated_and_contain_no_contact_details(): void
    {
        $organization = Organization::factory()->create();
        $actor = $this->administrator($organization);
        $member = OrganizationMember::factory()->gameMaster()->create(['organization_id' => $organization->id]);
        OrganizationMember::factory()->create();
        OrganizationMember::factory()->removed()->create(['organization_id' => $organization->id]);

        $page = $this->getJson('/api/v1/organizations/'.$organization->public_id.'/members?limit=1')->assertOk()->assertJsonCount(1, 'data');
        $next = $this->getJson('/api/v1/organizations/'.$organization->public_id.'/members?limit=1&cursor='.urlencode($page->json('meta.pagination.next_cursor')))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.has_more', false);

        $this->assertEqualsCanonicalizing([$actor->public_id, $member->user->public_id], [$page->json('data.0.user_id'), $next->json('data.0.user_id')]);
        $page->assertJsonMissingPath('data.0.id')->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.organization_id');
    }

    public function test_admin_adds_allowed_member_and_duplicate_is_409(): void
    {
        $organization = Organization::factory()->create();
        $this->administrator($organization);
        $target = User::factory()->create();
        $payload = ['user_id' => $target->public_id, 'role' => 'GAMEMASTER'];

        $this->postJson('/api/v1/organizations/'.$organization->public_id.'/members', $payload)->assertCreated()->assertJsonPath('data.user_id', $target->public_id)->assertJsonPath('data.role', 'GAMEMASTER');
        $this->postJson('/api/v1/organizations/'.$organization->public_id.'/members', $payload)->assertConflict()->assertJsonPath('error.code', 'CONFLICT');

        $this->assertDatabaseCount('organization_members', 2);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'organization.member_added.v1', 'organization_id' => $organization->id]);
    }

    public function test_removal_preserves_history_revokes_access_and_rejoin_creates_new_period(): void
    {
        $organization = Organization::factory()->create();
        $actor = $this->administrator($organization);
        $target = OrganizationMember::factory()->administrator()->create(['organization_id' => $organization->id]);
        $this->travelTo(now()->startOfSecond());

        $this->deleteJson('/api/v1/organizations/'.$organization->public_id.'/members/'.$target->user->public_id, ['reason' => 'Access ended'])->assertNoContent();

        $this->assertDatabaseHas('organization_members', ['id' => $target->id, 'status' => 'REMOVED']);
        $this->assertSame(now()->toISOString(), $target->fresh()->left_at->toISOString());
        $this->assertDatabaseHas('outbox_events', ['topic' => 'organization.member_removed.v1']);
        $this->authenticate($target->user);
        $this->getJson('/api/v1/organizations/'.$organization->public_id)->assertNotFound();
        $this->authenticate($actor);
        $this->postJson('/api/v1/organizations/'.$organization->public_id.'/members', ['user_id' => $target->user->public_id, 'role' => 'STAFF'])->assertCreated();
        $this->assertDatabaseCount('organization_members', 3);
        $this->assertDatabaseHas('organization_members', ['id' => $target->id, 'status' => 'REMOVED']);
    }

    public static function invalidRoles(): array
    {
        return ['global escalation' => ['SUPER_ADMIN'], 'captain' => ['CAPTAIN'], 'gameplay role' => ['SCANNER'], 'wrong spelling' => ['GAME_MASTER'], 'unknown' => ['OWNER']];
    }

    #[DataProvider('invalidRoles')]
    public function test_invalid_or_privileged_member_roles_are_422(string $role): void
    {
        $organization = Organization::factory()->create();
        $this->administrator($organization);
        $target = User::factory()->create();

        $this->postJson('/api/v1/organizations/'.$organization->public_id.'/members', ['user_id' => $target->public_id, 'role' => $role])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['fields' => ['role']]]]);

        $this->assertDatabaseCount('organization_members', 1);
        $this->assertDatabaseCount('role_assignments', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_gamemaster_has_only_limited_own_context_and_cannot_list_or_manage_members(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $actor = $this->authenticate();
        OrganizationMember::factory()->gameMaster()->create(['organization_id' => $organization->id, 'user_id' => $actor->id]);

        $this->getJson('/api/v1/organizations/'.$organization->public_id)->assertOk()->assertJsonMissingPath('data.created_at')->assertJsonMissingPath('data.settings');
        $this->getJson('/api/v1/organizations')->assertForbidden();
        $this->getJson('/api/v1/organizations/'.$other->public_id)->assertNotFound();
        $this->getJson('/api/v1/organizations/'.$organization->public_id.'/members')->assertForbidden();
        $this->postJson('/api/v1/organizations/'.$organization->public_id.'/members', ['user_id' => $actor->public_id, 'role' => 'ORGANIZATION_ADMIN'])->assertForbidden();
        $this->patchJson('/api/v1/organizations/'.$organization->public_id, ['name' => 'Escalated'])->assertForbidden();
        $this->deleteJson('/api/v1/organizations/'.$organization->public_id.'/members/'.$actor->public_id)->assertForbidden();
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_player_cannot_create_or_list_organizations(): void
    {
        $this->authenticate();

        $this->getJson('/api/v1/organizations')->assertForbidden()->assertJsonPath('error.code', 'AUTHORIZATION_DENIED');
        $this->postJson('/api/v1/organizations', ['name' => 'Illegal', 'slug' => 'illegal'])->assertForbidden();

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_internal_ids_and_unknown_public_ids_return_identical_not_found_errors(): void
    {
        $organization = Organization::factory()->create();
        $this->administrator($organization);

        $numeric = $this->getJson('/api/v1/organizations/'.$organization->id)->assertNotFound();
        $unknown = $this->getJson('/api/v1/organizations/'.Str::uuid())->assertNotFound();

        $this->assertSame($numeric->json(), $unknown->json());
        $this->getJson('/api/v1/organizations/not-a-uuid')->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public static function forbiddenPayloads(): array
    {
        return ['tenant override' => [['organization_id' => 'other']], 'global role' => [['role_code' => 'SUPER_ADMIN']], 'client status' => [['status' => 'ARCHIVED']], 'internal id' => [['id' => 123]], 'settings permissions' => [['commercial_settings_json' => ['is_super_admin' => true]]]];
    }

    #[DataProvider('forbiddenPayloads')]
    public function test_payload_cannot_override_ownership_status_or_privilege(array $payload): void
    {
        $organization = Organization::factory()->create();
        $this->administrator($organization);

        $this->patchJson('/api/v1/organizations/'.$organization->public_id, $payload + ['name' => 'Escalated'])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame($organization->name, $organization->fresh()->name);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertDatabaseCount('role_assignments', 0);
    }

    public function test_member_payload_cannot_override_route_organization(): void
    {
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $actor = $this->administrator($own);

        $this->postJson('/api/v1/organizations/'.$own->public_id.'/members', ['user_id' => $actor->public_id, 'role' => 'STAFF', 'organization_id' => $other->public_id])->assertUnprocessable();

        $this->assertDatabaseMissing('organization_members', ['organization_id' => $other->id]);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_slug_conflict_and_invalid_timezone_leave_organization_unchanged(): void
    {
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $this->administrator($own);

        $this->patchJson('/api/v1/organizations/'.$own->public_id, ['slug' => $other->slug])->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        $this->patchJson('/api/v1/organizations/'.$own->public_id, ['timezone_default' => 'Not/A_Timezone'])->assertUnprocessable();
        $this->patchJson('/api/v1/organizations/'.$own->public_id, ['slug' => 'Invalid Slug!'])->assertUnprocessable();
        $this->patchJson('/api/v1/organizations/'.$own->public_id, [])->assertUnprocessable();

        $this->assertSame($own->slug, $own->fresh()->slug);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public static function invalidListQueries(): array
    {
        return ['oversized' => ['limit=101'], 'negative' => ['limit=-1'], 'malformed cursor' => ['cursor=bad'], 'array cursor' => ['cursor[]=abc'], 'arbitrary filter' => ['organization_id=another'], 'sql sort' => ['sort=1%20OR%201=1']];
    }

    #[DataProvider('invalidListQueries')]
    public function test_invalid_list_controls_are_422(string $query): void
    {
        $this->administrator(Organization::factory()->create());

        $this->getJson('/api/v1/organizations?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_only_existing_public_users_can_be_added_and_removed(): void
    {
        $organization = Organization::factory()->create();
        $actor = $this->administrator($organization);

        $this->postJson('/api/v1/organizations/'.$organization->public_id.'/members', ['user_id' => (string) $actor->id, 'role' => 'STAFF'])->assertUnprocessable();
        $this->postJson('/api/v1/organizations/'.$organization->public_id.'/members', ['user_id' => (string) Str::uuid(), 'role' => 'STAFF'])->assertNotFound();
        $this->deleteJson('/api/v1/organizations/'.$organization->public_id.'/members/'.$actor->id)->assertNotFound();

        $this->assertDatabaseCount('organization_members', 1);
        $this->assertDatabaseCount('outbox_events', 0);
    }
}

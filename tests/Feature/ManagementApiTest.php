<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameSession;
use App\Models\GameSessionPlayer;
use App\Models\GameSessionTeam;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $device = UserDevice::factory()->create(['user_id' => $user->id, 'platform' => 'web']);
        $plain = Str::random(64);
        $token = $user->tokens()->make(['name' => 'management-test', 'token' => hash('sha256', $plain), 'abilities' => ['control-center']]);
        $token->forceFill(['user_device_id' => $device->id])->save();
        $this->withToken($plain);

        return $user;
    }

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->create();
        RoleAssignment::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    public function test_global_user_api_requires_super_admin_and_never_returns_credentials(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $member = $this->authenticate();
        $this->getJson('/api/v1/admin/users')->assertForbidden();

        $admin = $this->makeSuperAdmin();
        $target = User::factory()->create(['name' => 'Searchable Account', 'email' => 'searchable@nexo.test']);
        $this->bearerFor($target);
        $response = $this->withToken($this->bearerFor($admin))->getJson('/api/v1/admin/users?q=searchable&limit=1')->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->public_id)
            ->assertJsonMissingPath('data.0.password_hash')->assertJsonMissingPath('data.0.tokens')->assertJsonMissingPath('data.0.devices');
        $this->assertSame('ACTIVE', $member->fresh()->status);
    }

    public function test_global_user_search_uses_server_pagination_and_account_filters(): void
    {
        $admin = $this->makeSuperAdmin();
        User::factory()->create(['name' => 'Needle One', 'email' => 'one@nexo.test', 'status' => 'SUSPENDED']);
        User::factory()->create(['name' => 'Needle Two', 'email' => 'two@nexo.test']);

        $this->withToken($this->bearerFor($admin))->getJson('/api/v1/admin/users?q=needle&status=SUSPENDED&limit=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'SUSPENDED')
            ->assertJsonPath('meta.pagination.has_more', false);
    }

    public function test_non_super_admin_cannot_use_user_mutation_endpoints(): void
    {
        $actor = $this->authenticate();
        $target = User::factory()->create();
        $payload = ['reason' => 'Unauthorized mutation'];

        $this->withToken($this->bearerFor($actor))->postJson('/api/v1/admin/users', [
            'name' => 'Denied', 'mobile' => '+989121234567', 'password' => 'secure-managed-password', ...$payload,
        ])->assertForbidden();
        $this->patchJson('/api/v1/admin/users/'.$target->public_id, ['name' => 'Denied', ...$payload])->assertForbidden();
        $this->postJson('/api/v1/admin/users/'.$target->public_id.'/password', ['password' => 'another-secure-password', ...$payload])->assertForbidden();
        $this->deleteJson('/api/v1/admin/users/'.$target->public_id, $payload)->assertForbidden();
    }

    public function test_super_admin_can_create_edit_reset_and_soft_delete_a_user(): void
    {
        $admin = $this->makeSuperAdmin();
        $token = $this->bearerFor($admin);

        $created = $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name' => 'Managed Account', 'email' => 'managed@nexo.test', 'mobile' => '+989121234567',
            'password' => 'secure-managed-password', 'reason' => 'Provisioned for operations',
        ])->assertCreated()->assertJsonPath('data.name', 'Managed Account');
        $userId = $created->json('data.id');

        $this->patchJson('/api/v1/admin/users/'.$userId, [
            'name' => 'Updated Managed Account', 'reason' => 'Corrected account profile',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Managed Account');
        $this->postJson('/api/v1/admin/users/'.$userId.'/password', [
            'password' => 'another-secure-password', 'reason' => 'Credential rotation',
        ])->assertNoContent();
        $this->deleteJson('/api/v1/admin/users/'.$userId, ['reason' => 'Account no longer needed'])->assertNoContent();

        $user = User::withTrashed()->where('public_id', $userId)->sole();
        $this->assertNotNull($user->deleted_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.created', 'resource_id' => $userId]);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.updated', 'resource_id' => $userId]);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.password_reset', 'resource_id' => $userId]);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.deleted', 'resource_id' => $userId]);
    }

    public function test_last_active_super_admin_cannot_be_soft_deleted_and_deleted_users_can_be_restored(): void
    {
        $admin = $this->makeSuperAdmin();
        $token = $this->bearerFor($admin);

        $this->withToken($token)->deleteJson('/api/v1/admin/users/'.$admin->public_id, ['reason' => 'Attempt'])->assertConflict();
        $target = User::factory()->create();
        $this->deleteJson('/api/v1/admin/users/'.$target->public_id, ['reason' => 'Remove test account'])->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->postJson('/api/v1/admin/users/'.$target->public_id.'/restore', ['reason' => 'Restore test account'])
            ->assertOk()->assertJsonPath('data.id', $target->public_id);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }

    public function test_account_status_requires_reason_revokes_tokens_and_is_audited(): void
    {
        $admin = $this->makeSuperAdmin();
        $target = User::factory()->create();
        $device = UserDevice::factory()->create(['user_id' => $target->id]);
        $plain = Str::random(64);
        $token = $target->tokens()->make(['name' => 'account-token', 'token' => hash('sha256', $plain), 'abilities' => ['*']]);
        $token->forceFill(['user_device_id' => $device->id])->save();
        $this->withToken($this->bearerFor($admin))->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', ['status' => 'SUSPENDED'])
            ->assertUnprocessable();

        $this->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', ['status' => 'SUSPENDED', 'reason' => 'Policy review'])
            ->assertOk()->assertJsonPath('data.status', 'SUSPENDED')->assertJsonMissingPath('data.password_hash')->assertJsonMissingPath('data.tokens');
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.status_changed', 'resource_id' => $target->public_id, 'actor_id' => $admin->id, 'reason' => 'Policy review']);
        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();

        $this->withToken($this->bearerFor($admin))->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', ['status' => 'ACTIVE', 'reason' => 'Review complete'])
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_block_unblock_is_separately_audited_and_self_status_change_is_denied(): void
    {
        $admin = $this->makeSuperAdmin();
        $target = User::factory()->create();
        $token = $this->bearerFor($admin);
        $this->withToken($token)->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', ['status' => 'BLOCKED', 'reason' => 'Security incident'])->assertOk();
        $this->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', ['status' => 'SUSPENDED', 'reason' => 'Attempted downgrade'])->assertConflict();
        $this->patchJson('/api/v1/admin/users/'.$target->public_id.'/status', ['status' => 'ACTIVE', 'reason' => 'Incident cleared'])->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'user.block_lifted', 'resource_id' => $target->public_id, 'reason' => 'Incident cleared']);
        $this->patchJson('/api/v1/admin/users/'.$admin->public_id.'/status', ['status' => 'SUSPENDED', 'reason' => 'Self action'])->assertConflict();
    }

    public function test_last_active_super_admin_cannot_be_suspended_blocked_or_revoked(): void
    {
        $admin = $this->makeSuperAdmin();
        $token = $this->bearerFor($admin);
        $this->withToken($token)->patchJson('/api/v1/admin/users/'.$admin->public_id.'/status', ['status' => 'BLOCKED', 'reason' => 'Attempt'])->assertConflict();
        $this->deleteJson('/api/v1/admin/admins/'.$admin->public_id, ['reason' => 'Attempt'])->assertConflict();
        $this->assertSame('ACTIVE', $admin->fresh()->status);
        $this->assertSame(1, RoleAssignment::query()->where('role_code', RoleAssignment::SUPER_ADMIN)->whereNull('revoked_at')->count());
    }

    public function test_global_admin_grants_require_super_admin_and_role_history_is_preserved(): void
    {
        $organization = Organization::factory()->create();
        $orgAdmin = OrganizationMember::factory()->administrator()->create(['organization_id' => $organization->id])->user;
        $target = User::factory()->create();
        $this->withToken($this->bearerFor($orgAdmin))->postJson('/api/v1/admin/admins/'.$target->public_id, ['reason' => 'Attempted escalation'])->assertForbidden();

        $admin = $this->makeSuperAdmin();
        $token = $this->bearerFor($admin);
        $this->withToken($token)->postJson('/api/v1/admin/admins/'.$target->public_id, ['reason' => 'Trusted operator'])->assertCreated()->assertJsonPath('data.role', 'SUPER_ADMIN');
        $this->deleteJson('/api/v1/admin/admins/'.$target->public_id, ['reason' => 'Operator rotation'])->assertNoContent();
        $this->assertDatabaseHas('audit_events', ['action' => 'role_assignment.granted', 'resource_id' => $target->public_id, 'reason' => 'Trusted operator']);
        $this->assertDatabaseHas('audit_events', ['action' => 'role_assignment.revoked', 'resource_id' => $target->public_id, 'reason' => 'Operator rotation']);
        $assignments = RoleAssignment::query()->where('role_code', RoleAssignment::SUPER_ADMIN)->where('user_id', $target->id)->get();
        $this->assertCount(1, $assignments);
        $this->assertNotNull($assignments->sole()->revoked_at);
    }

    public function test_organization_membership_role_change_is_scoped_and_audited(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $actor = $this->authenticate();
        OrganizationMember::factory()->administrator()->create(['organization_id' => $organization->id, 'user_id' => $actor->id]);
        $member = OrganizationMember::factory()->create(['organization_id' => $organization->id]);
        $foreign = OrganizationMember::factory()->create(['organization_id' => $other->id]);

        $this->patchJson('/api/v1/admin/organizations/'.$organization->public_id.'/members/'.$member->user->public_id.'/role', ['role' => 'GAMEMASTER', 'reason' => 'Operational assignment'])
            ->assertOk()->assertJsonPath('data.role', 'GAMEMASTER');
        $this->patchJson('/api/v1/admin/organizations/'.$organization->public_id.'/members/'.$foreign->user->public_id.'/role', ['role' => 'ORGANIZATION_ADMIN', 'reason' => 'Cross tenant attempt'])
            ->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['action' => 'organization.member_role_changed.v1', 'organization_id' => $organization->id, 'reason' => 'Operational assignment']);
    }

    public function test_super_admin_can_change_membership_status_without_changing_global_account_status(): void
    {
        $organization = Organization::factory()->create();
        $admin = $this->makeSuperAdmin();
        $target = User::factory()->create(['status' => 'ACTIVE']);
        $member = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $target->id, 'status' => 'ACTIVE']);
        $token = $this->bearerFor($admin);

        $this->withToken($token)->patchJson('/api/v1/admin/organizations/'.$organization->public_id.'/members/'.$target->public_id.'/status', [
            'status' => 'SUSPENDED', 'reason' => 'Organization access review',
        ])->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
        $this->assertSame('ACTIVE', $target->fresh()->status);
        $this->assertDatabaseHas('organization_members', ['id' => $member->id, 'status' => 'SUSPENDED', 'left_at' => null]);
        $this->assertDatabaseHas('audit_events', ['action' => 'organization.member_status_changed.v1', 'organization_id' => $organization->id, 'reason' => 'Organization access review']);

        $this->patchJson('/api/v1/admin/organizations/'.$organization->public_id.'/members/'.$target->public_id.'/status', [
            'status' => 'ACTIVE', 'reason' => 'Organization access restored',
        ])->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->assertSame('ACTIVE', $target->fresh()->status);
    }

    public function test_player_visibility_is_scoped_to_organization_and_details_keep_history_read_only(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $actor = $this->authenticate();
        OrganizationMember::factory()->administrator()->create(['organization_id' => $organization->id, 'user_id' => $actor->id]);
        $visible = User::factory()->create(['email' => 'private@nexo.test', 'mobile' => '+989121234567']);
        $hidden = User::factory()->create();
        Team::factory()->create(['organization_id' => $organization->id, 'captain_user_id' => $visible->id]);
        Team::factory()->create(['organization_id' => $other->id, 'captain_user_id' => $hidden->id]);

        $response = $this->getJson('/api/v1/admin/players')->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $visible->public_id)->assertJsonMissing(['email' => 'private@nexo.test']);
        $this->getJson('/api/v1/admin/players/'.$visible->public_id)->assertOk()->assertJsonPath('data.user.id', $visible->public_id);
        $this->getJson('/api/v1/admin/players/'.$hidden->public_id)->assertNotFound();
        $this->getJson('/api/v1/admin/players?status=ACTIVE')->assertOk();
    }

    public function test_game_master_player_reads_are_limited_to_assigned_sessions_and_safe_fields(): void
    {
        $organization = Organization::factory()->create();
        $gameMaster = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'role' => 'GAMEMASTER'])->user;
        $game = Game::factory()->create(['organization_id' => $organization->id]);
        $session = GameSession::factory()->create(['game_id' => $game->id, 'organization_id' => $organization->id]);
        RoleAssignment::factory()->create(['user_id' => $gameMaster->id, 'organization_id' => $organization->id, 'resource_type' => 'game_session', 'resource_id' => $session->public_id, 'role_code' => 'GAMEMASTER']);
        $player = User::factory()->create(['email' => 'secret@nexo.test', 'mobile' => '+989121112222']);
        $registration = GameSessionTeam::factory()->create(['game_session_id' => $session->id]);
        GameSessionPlayer::factory()->create(['user_id' => $player->id, 'game_session_id' => $session->id, 'game_session_team_id' => $registration->id]);
        $foreign = User::factory()->create();

        $response = $this->withToken($this->bearerFor($gameMaster))->getJson('/api/v1/admin/players')->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $player->public_id)->assertJsonMissing(['email' => 'secret@nexo.test'])->assertJsonMissingPath('data.0.status');
        $this->getJson('/api/v1/admin/players/'.$player->public_id)->assertOk()->assertJsonMissingPath('data.user.email')->assertJsonMissingPath('data.user.status');
        $this->getJson('/api/v1/admin/players/'.$foreign->public_id)->assertNotFound();
        $this->getJson('/api/v1/admin/players?status=ACTIVE')->assertForbidden();
    }

    public function test_capabilities_are_server_derived_and_legacy_me_contract_remains_unchanged(): void
    {
        $admin = $this->makeSuperAdmin();
        $token = $this->bearerFor($admin);
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.roles.0', 'PLAYER')->assertJsonMissingPath('data.capabilities');
        $this->getJson('/api/v1/me/capabilities')->assertOk()->assertJsonPath('data.global_roles.0', 'SUPER_ADMIN')->assertJsonFragment(['admin.users.view'])->assertJsonFragment(['admin.audit.view']);
    }

    public function test_audit_history_redacts_secret_metadata(): void
    {
        $admin = $this->makeSuperAdmin();
        $target = User::factory()->create();
        app(AuditService::class)->record('user.test_event', 'user', $target->public_id, 'Audit test', ['token' => 'hidden-token', 'safe' => 'visible'], $admin->id);

        $response = $this->withToken($this->bearerFor($admin))->getJson('/api/v1/admin/users/'.$target->public_id)->assertOk();
        $response->assertJsonFragment(['safe' => 'visible'])->assertJsonMissing(['token' => 'hidden-token']);
        $this->assertStringNotContainsString('hidden-token', $response->getContent());
    }

    private function bearerFor(User $user): string
    {
        $device = UserDevice::factory()->create(['user_id' => $user->id, 'platform' => 'web']);
        $plain = Str::random(64);
        $token = $user->tokens()->make(['name' => 'management-test', 'token' => hash('sha256', $plain), 'abilities' => ['control-center']]);
        $token->forceFill(['user_device_id' => $device->id])->save();

        return $plain;
    }
}

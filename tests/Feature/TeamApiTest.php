<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamApiTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $device = UserDevice::factory()->create(['user_id' => $user->id]);
        $token = Str::random(64);
        $accessToken = $user->tokens()->make(['name' => 'team-test', 'token' => hash('sha256', $token), 'abilities' => ['*']]);
        $accessToken->forceFill(['user_device_id' => $device->id])->save();
        $this->withToken($token);

        return $user;
    }

    public function test_creation_persists_captain_membership_and_correlated_events_without_private_ids(): void
    {
        $actor = $this->authenticate();
        $response = $this->withHeader('X-Request-Id', 'team-create')->postJson('/api/v1/teams', ['name' => 'NEXO Explorers'])
            ->assertCreated()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.eligible_for_game', false)->assertJsonPath('data.captain_user_id', $actor->public_id)
            ->assertJsonPath('data.organization', null)->assertJsonMissingPath('data.members.0.email')
            ->assertJsonMissingPath('data.members.0.id')->assertJsonMissingPath('data.captain.email');
        $team = Team::sole();
        $this->assertSame($team->public_id, $response->json('data.id'));
        $this->assertTrue(Str::isUuid($response->json('data.id')));
        $this->assertDatabaseHas('team_members', ['team_id' => $team->id, 'user_id' => $actor->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('audit_events', ['action' => 'team.created.v1', 'actor_id' => $actor->id, 'request_id' => 'team-create']);
        $this->assertDatabaseCount('outbox_events', 2);
        $this->assertDatabaseCount('team_member_events', 1);
        $payload = json_decode(DB::table('outbox_events')->where('topic', 'team.member_joined.v1')->value('payload'), true);
        $this->assertSame($actor->public_id, $payload['data']['user_id']);
        $this->assertSame($team->public_id, $payload['team_id']);
        $this->assertSame(1, $payload['data']['member_count']);
    }

    public function test_team_api_requires_authentication(): void
    {
        $this->postJson('/api/v1/teams', ['name' => 'Denied'])->assertUnauthorized()->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $this->assertDatabaseCount('teams', 0);
    }

    public static function inactiveStatuses(): array
    {
        return [['SUSPENDED'], ['BLOCKED']];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_inactive_users_cannot_create_or_join(string $status): void
    {
        $actor = $this->authenticate(User::factory()->create(['status' => $status]));
        $this->postJson('/api/v1/teams', ['name' => 'Denied'])->assertForbidden();
        $this->postJson('/api/v1/teams/join', ['invite_code' => 'unknown'])->assertForbidden();
        $this->assertDatabaseMissing('teams', ['captain_user_id' => $actor->id]);
    }

    public static function invalidCreateInputs(): array
    {
        return [
            'missing name' => [[], 'name'], 'blank' => [['name' => '  '], 'name'],
            'too long' => [['name' => str_repeat('x', 256)], 'name'], 'array' => [['name' => []], 'name'],
            'organization injection' => [['name' => 'NEXO', 'organization_id' => 'guessed'], 'organization_id'],
            'captain injection' => [['name' => 'NEXO', 'captain_user_id' => 1], 'captain_user_id'],
            'status injection' => [['name' => 'NEXO', 'status' => 'ARCHIVED'], 'status'],
            'media unavailable' => [['name' => 'NEXO', 'avatar_media_id' => 1], 'avatar_media_id'],
        ];
    }

    #[DataProvider('invalidCreateInputs')]
    public function test_creation_rejects_invalid_or_untrusted_fields(array $input, string $field): void
    {
        $this->authenticate();
        $this->postJson('/api/v1/teams', $input)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_captain_updates_name_but_not_privileged_fields(): void
    {
        $team = Team::factory()->create();
        $this->authenticate($team->captain);
        $this->patchJson('/api/v1/teams/'.$team->public_id, ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
        foreach (['status', 'captain_user_id', 'organization_id', 'member_count', 'id'] as $field) {
            $this->patchJson('/api/v1/teams/'.$team->public_id, ['name' => 'Wrong', $field => 1])->assertUnprocessable();
        }
        $this->assertSame('Renamed', $team->fresh()->name);
        $this->assertDatabaseHas('outbox_events', ['topic' => 'team.updated.v1']);
    }

    public function test_invite_secret_is_returned_once_and_never_stored_or_listed(): void
    {
        $team = Team::factory()->create();
        $this->authenticate($team->captain);
        $response = $this->postJson('/api/v1/teams/'.$team->public_id.'/invites', ['max_uses' => 2])->assertCreated();
        $code = $response->json('data.invite_code');
        $invite = TeamInvite::sole();
        $this->assertSame(16, strlen($code));
        $this->assertSame(hash('sha256', $code), $invite->code_hash);
        $this->assertStringNotContainsString($code, $invite->toJson());
        $this->assertStringNotContainsString($invite->code_hash, $invite->toJson());
        $this->getJson('/api/v1/teams/'.$team->public_id.'/invites')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.invite_code')->assertJsonMissingPath('data.0.code_hash');
        $this->assertStringNotContainsString($code, DB::table('outbox_events')->value('payload'));
        $this->assertStringNotContainsString($invite->code_hash, DB::table('outbox_events')->value('payload'));
    }

    public function test_join_leave_remove_and_rejoin_preserve_membership_periods(): void
    {
        $team = Team::factory()->create();
        TeamInvite::factory()->code('history-code')->create(['team_id' => $team->id]);
        $member = $this->authenticate();
        $requestId = $this->postJson('/api/v1/teams/join', ['invite_code' => 'history-code'])
            ->assertOk()->assertJsonPath('data.status', 'PENDING')->json('data.id');
        $this->authenticate($team->captain);
        $this->postJson('/api/v1/teams/'.$team->public_id.'/join-requests/'.$requestId.'/accept')->assertOk()
            ->assertJsonPath('data.status', 'ACCEPTED');
        $this->authenticate($member);
        $this->postJson('/api/v1/teams/join', ['invite_code' => 'history-code'])->assertConflict()->assertJsonPath('error.code', 'ALREADY_TEAM_MEMBER');
        $this->postJson('/api/v1/teams/'.$team->public_id.'/leave')->assertNoContent();
        TeamInvite::factory()->code('history-code-two')->create(['team_id' => $team->id]);
        $requestId = $this->postJson('/api/v1/teams/join', ['invite_code' => 'history-code-two'])
            ->assertOk()->assertJsonPath('data.status', 'PENDING')->json('data.id');
        $this->authenticate($team->captain);
        $this->postJson('/api/v1/teams/'.$team->public_id.'/join-requests/'.$requestId.'/accept')->assertOk()
            ->assertJsonPath('data.status', 'ACCEPTED');
        $this->deleteJson('/api/v1/teams/'.$team->public_id.'/members/'.$member->public_id)->assertNoContent();
        TeamInvite::factory()->code('history-code-three')->create(['team_id' => $team->id]);
        $this->authenticate($member);
        $requestId = $this->postJson('/api/v1/teams/join', ['invite_code' => 'history-code-three'])
            ->assertOk()->assertJsonPath('data.status', 'PENDING')->json('data.id');
        $this->authenticate($team->captain);
        $this->postJson('/api/v1/teams/'.$team->public_id.'/join-requests/'.$requestId.'/accept')->assertOk()
            ->assertJsonPath('data.status', 'ACCEPTED');
        $this->assertSame(['LEFT', 'REMOVED', 'ACTIVE'], TeamMember::where('user_id', $member->id)->orderBy('id')->pluck('status')->all());
        $this->assertSame(3, TeamInvite::query()->sum('uses_count'));
        $this->assertDatabaseHas('team_members', ['user_id' => $member->id, 'status' => 'REMOVED', 'removed_by' => $team->captain_user_id]);
        $this->assertDatabaseCount('team_member_events', 5);
    }

    public function test_captain_transfer_is_audited_retry_safe_and_required_before_leaving(): void
    {
        $team = Team::factory()->create();
        $member = TeamMember::factory()->create(['team_id' => $team->id])->user;
        $oldCaptain = $this->authenticate($team->captain);
        $base = '/api/v1/teams/'.$team->public_id;
        $this->postJson($base.'/leave')->assertConflict()->assertJsonPath('error.code', 'ACTION_NOT_ALLOWED');
        $this->deleteJson($base.'/members/'.$oldCaptain->public_id)->assertConflict();
        $response = $this->withHeader('Idempotency-Key', 'transfer-once')->postJson($base.'/transfer-captaincy', ['user_id' => $member->public_id])->assertOk();
        $this->postJson($base.'/transfer-captaincy', ['user_id' => $member->public_id])->assertOk()->assertExactJson($response->json());
        $this->postJson($base.'/transfer-captaincy', ['user_id' => $oldCaptain->public_id])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->withoutHeader('Idempotency-Key')->postJson($base.'/leave')->assertNoContent();
        $this->assertSame($member->id, $team->fresh()->captain_user_id);
        $payload = json_decode(DB::table('outbox_events')->where('topic', 'team.captain_changed.v1')->value('payload'), true);
        $this->assertSame($oldCaptain->public_id, $payload['data']['old_captain_user_id']);
        $this->assertSame($member->public_id, $payload['data']['new_captain_user_id']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'team.captain_changed.v1')->count());
    }

    public function test_create_and_join_idempotency_preserve_logical_result_and_invite_uses(): void
    {
        $actor = $this->authenticate();
        $first = $this->withHeader('Idempotency-Key', 'create-once')->postJson('/api/v1/teams', ['name' => 'One'])->assertCreated();
        $this->postJson('/api/v1/teams', ['name' => 'One'])->assertCreated()->assertExactJson($first->json());
        $this->postJson('/api/v1/teams', ['name' => 'Two'])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('teams', 1);
        $other = Team::factory()->create();
        $invite = TeamInvite::factory()->code('join-once')->create(['team_id' => $other->id, 'max_uses' => 1]);
        $joined = $this->withHeader('Idempotency-Key', 'join-once-key')->postJson('/api/v1/teams/join', ['invite_code' => 'join-once'])->assertOk();
        $this->postJson('/api/v1/teams/join', ['invite_code' => 'join-once'])->assertOk()->assertExactJson($joined->json());
        $this->authenticate($other->captain);
        $this->postJson('/api/v1/teams/'.$other->public_id.'/join-requests/'.$joined->json('data.id').'/accept')
            ->assertOk()->assertJsonPath('data.status', 'ACCEPTED');
        $this->assertSame(1, $invite->fresh()->uses_count);
        $this->assertSame(2, TeamMember::where('user_id', $actor->id)->where('status', 'ACTIVE')->count());
    }

    public function test_archive_retains_history_and_disables_invites_and_join(): void
    {
        $team = Team::factory()->create();
        $invite = TeamInvite::factory()->code('archive-code')->create(['team_id' => $team->id]);
        $this->authenticate($team->captain);
        $base = '/api/v1/teams/'.$team->public_id;
        $this->postJson($base.'/archive')->assertOk()->assertJsonPath('data.status', 'ARCHIVED')->assertJsonPath('data.eligible_for_game', false);
        $this->postJson($base.'/archive')->assertOk();
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'data.members');
        $this->postJson($base.'/invites')->assertConflict();
        $this->authenticate();
        $this->postJson('/api/v1/teams/join', ['invite_code' => 'archive-code'])->assertConflict();
        $this->assertModelExists($team);
        $this->assertModelExists($invite);
        $this->assertDatabaseCount('team_members', 1);
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'team.archived.v1')->count());
    }

    public function test_revoke_is_idempotent_and_invite_ids_are_scoped_to_team(): void
    {
        $team = Team::factory()->create();
        $invite = TeamInvite::factory()->code('revoke-code')->create(['team_id' => $team->id]);
        $otherInvite = TeamInvite::factory()->create();
        $this->authenticate($team->captain);
        $base = '/api/v1/teams/'.$team->public_id.'/invites/';
        $this->deleteJson($base.$otherInvite->public_id)->assertNotFound();
        $this->deleteJson($base.$invite->public_id)->assertNoContent();
        $this->deleteJson($base.$invite->public_id)->assertNoContent();
        $this->authenticate();
        $this->postJson('/api/v1/teams/join', ['invite_code' => 'revoke-code'])->assertConflict()->assertJsonPath('error.code', 'INVITE_REVOKED');
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'team.invite_revoked.v1')->count());
    }

    public function test_lists_are_paginated_and_unrelated_personal_teams_are_hidden(): void
    {
        $actor = $this->authenticate();
        Team::factory()->count(3)->create(['captain_user_id' => $actor->id]);
        $unrelated = Team::factory()->create();
        $first = $this->getJson('/api/v1/teams?limit=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.has_more', true);
        $this->getJson('/api/v1/teams?limit=2&cursor='.$first->json('meta.pagination.next_cursor'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.has_more', false);
        $this->getJson('/api/v1/teams/'.$unrelated->public_id)->assertNotFound();
        $this->getJson('/api/v1/teams/'.$unrelated->id)->assertNotFound();
        $this->getJson('/api/v1/teams?cursor=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/teams?limit=101')->assertUnprocessable();
        $this->getJson('/api/v1/teams?sort=users.password_hash')->assertUnprocessable();
    }

    public static function captainOperations(): array
    {
        return [['PATCH', '', ['name' => 'Hacked']], ['POST', '/invites', []], ['GET', '/invites', []],
            ['POST', '/archive', []], ['POST', '/transfer-captaincy', ['user_id' => '00000000-0000-4000-8000-000000000001']],
            ['DELETE', '/members/00000000-0000-4000-8000-000000000001', []],
            ['DELETE', '/invites/00000000-0000-4000-8000-000000000001', []]];
    }

    #[DataProvider('captainOperations')]
    public function test_member_cannot_perform_captain_operations(string $method, string $suffix, array $input): void
    {
        $team = Team::factory()->create();
        $member = TeamMember::factory()->create(['team_id' => $team->id])->user;
        $this->authenticate($member);
        $this->json($method, '/api/v1/teams/'.$team->public_id.$suffix, $input)->assertForbidden();
        $this->assertDatabaseCount('outbox_events', 0);
    }

    #[DataProvider('captainOperations')]
    public function test_cross_tenant_operations_are_not_found(string $method, string $suffix, array $input): void
    {
        $own = Organization::factory()->create();
        $other = Organization::factory()->create();
        $actor = $this->authenticate();
        OrganizationMember::factory()->administrator()->create(['user_id' => $actor->id, 'organization_id' => $own->id]);
        $team = Team::factory()->create(['organization_id' => $other->id]);
        $this->json($method, '/api/v1/teams/'.$team->public_id.$suffix, $input)->assertNotFound();
        $this->getJson('/api/v1/teams')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('outbox_events', 0);
    }
}

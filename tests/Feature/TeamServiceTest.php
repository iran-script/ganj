<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\GameSession;
use App\Models\GameSessionTeam;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Organizations\OrganizationScope;
use App\Services\Teams\TeamScope;
use App\Services\Teams\TeamService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamServiceTest extends TestCase
{
    use RefreshDatabase;

    public static function eligibilityCounts(): array
    {
        return [[1, false], [3, false], [4, true], [8, true]];
    }

    #[DataProvider('eligibilityCounts')]
    public function test_eligibility_is_derived_from_active_members(int $count, bool $eligible): void
    {
        $team = Team::factory()->create();
        TeamMember::factory()->count($count - 1)->create(['team_id' => $team->id]);
        TeamMember::factory()->left()->create(['team_id' => $team->id]);
        $profile = app(TeamService::class)->show($team->captain, $team->public_id);
        $this->assertSame($count, $profile['member_count']);
        $this->assertSame($eligible, $profile['eligible_for_game']);
    }

    public function test_ninth_join_is_rejected_without_consuming_invite_or_creating_events(): void
    {
        $team = Team::factory()->create();
        TeamMember::factory()->count(7)->create(['team_id' => $team->id]);
        $invite = TeamInvite::factory()->code('full-team')->create(['team_id' => $team->id]);
        $actor = User::factory()->create();
        try {
            app(TeamService::class)->execute($actor, 'join', ['invite_code' => 'full-team']);
            $this->fail('A ninth member must be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame('TEAM_FULL', $exception->errorCode);
        }
        $this->assertSame(8, $team->activeMembers()->count());
        $this->assertSame(0, $invite->fresh()->uses_count);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public static function invalidInvites(): array
    {
        return [
            'expired' => ['expired', 'INVITE_EXPIRED'],
            'revoked' => ['revoked', 'INVITE_REVOKED'],
            'exhausted' => ['exhausted', 'INVITE_EXHAUSTED'],
            'unknown' => ['unknown', 'RESOURCE_NOT_FOUND'],
        ];
    }

    #[DataProvider('invalidInvites')]
    public function test_invalid_invites_do_not_create_membership(string $state, string $error): void
    {
        $this->freezeTime();
        $team = Team::factory()->create();
        $attributes = match ($state) {
            'expired' => ['expires_at' => now()],
            'revoked' => ['revoked_at' => now()],
            'exhausted' => ['max_uses' => 1, 'uses_count' => 1],
            default => [],
        };
        TeamInvite::factory()->code('valid-code')->create($attributes + ['team_id' => $team->id]);
        try {
            app(TeamService::class)->execute(User::factory()->create(), 'join', ['invite_code' => $state === 'unknown' ? 'wrong' : 'valid-code']);
            $this->fail('Invalid invite accepted.');
        } catch (ApiException $exception) {
            $this->assertSame($error, $exception->errorCode);
        }
        $this->assertDatabaseCount('team_members', 1);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_eligibility_recalculates_after_leave_and_removal(): void
    {
        $team = Team::factory()->create();
        $members = TeamMember::factory()->count(3)->create(['team_id' => $team->id]);
        $service = app(TeamService::class);
        $service->execute($members[0]->user, 'leave', teamId: $team->public_id);
        $this->assertFalse($service->show($team->captain, $team->public_id)['eligible_for_game']);
        $service->execute($team->captain, 'remove', teamId: $team->public_id, targetId: $members[1]->user->public_id);
        $this->assertSame(2, $service->show($team->captain, $team->public_id)['member_count']);
        $this->assertSame(4, $team->members()->count());
    }

    public static function invalidCaptainTargets(): array
    {
        return [['non-member'], ['LEFT'], ['REMOVED'], ['other-team']];
    }

    #[DataProvider('invalidCaptainTargets')]
    public function test_transfer_rejects_non_active_same_team_target(string $state): void
    {
        $team = Team::factory()->create();
        $target = User::factory()->create();
        if ($state === 'LEFT') {
            TeamMember::factory()->left()->create(['team_id' => $team->id, 'user_id' => $target->id]);
        } elseif ($state === 'REMOVED') {
            TeamMember::factory()->removed()->create(['team_id' => $team->id, 'user_id' => $target->id]);
        } elseif ($state === 'other-team') {
            TeamMember::factory()->create(['user_id' => $target->id]);
        }
        try {
            app(TeamService::class)->execute($team->captain, 'transfer', ['user_id' => $target->public_id], $team->public_id);
            $this->fail('Invalid captain target accepted.');
        } catch (ApiException $exception) {
            $this->assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
        }
        $this->assertSame($team->captain_user_id, $team->fresh()->captain_user_id);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_organization_association_requires_current_authorized_context_and_scope(): void
    {
        $organization = Organization::factory()->create();
        $admin = OrganizationMember::factory()->administrator()->create(['organization_id' => $organization->id])->user;
        $context = app(OrganizationScope::class)->resolve($admin, $organization->public_id);
        $team = app(TeamService::class)->createForOrganization($admin, $context, ['name' => 'Tenant Team']);
        $this->assertSame($organization->id, $team->organization_id);
        $this->assertSame($organization->public_id, app(TeamService::class)->show($admin, $team->public_id)['organization']['id']);
        $outsider = User::factory()->create();
        $query = app(TeamScope::class)->teams($outsider);
        $this->assertFalse($query->where('public_id', $team->public_id)->exists());
        try {
            app(TeamService::class)->createForOrganization($outsider, $context, ['name' => 'Forged']);
            $this->fail('Context actor cannot grant authority to another caller.');
        } catch (ApiException $exception) {
            $this->assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
        }
        $this->assertDatabaseCount('teams', 1);
    }

    public function test_tenant_join_denies_outsiders_even_with_valid_invite(): void
    {
        $organization = Organization::factory()->create();
        $team = Team::factory()->create(['organization_id' => $organization->id]);
        $invite = TeamInvite::factory()->code('tenant-secret')->create(['team_id' => $team->id]);
        try {
            app(TeamService::class)->execute(User::factory()->create(), 'join', ['invite_code' => 'tenant-secret']);
            $this->fail('Cross-tenant invite must not bypass scope.');
        } catch (ApiException $exception) {
            $this->assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
        }
        $this->assertSame(0, $invite->fresh()->uses_count);
    }

    public function test_retained_team_query_rechecks_tenant_membership_revocation(): void
    {
        $org = Organization::factory()->create();
        $membership = OrganizationMember::factory()->administrator()->create(['organization_id' => $org->id]);
        $team = Team::factory()->create(['organization_id' => $org->id, 'captain_user_id' => $membership->user_id]);
        $query = app(TeamScope::class)->teams($membership->user)->where('public_id', $team->public_id);
        $membership->forceFill(['status' => 'REMOVED', 'left_at' => now()])->save();
        $this->assertFalse($query->exists());
    }

    public static function policyRoles(): array
    {
        return [
            'captain' => ['captain', true, true, true],
            'member' => ['member', false, false, false],
            'game master' => ['gamemaster', false, false, false],
            'org admin' => ['admin', true, true, false],
            'super admin' => ['super', true, true, false],
            'outsider' => ['outsider', false, false, false],
        ];
    }

    #[DataProvider('policyRoles')]
    public function test_policy_matrix_does_not_grant_persistent_membership_authority_to_game_master(string $role, bool $update, bool $support, bool $remove): void
    {
        $org = Organization::factory()->create();
        $team = Team::factory()->create(['organization_id' => $org->id]);
        $actor = $role === 'captain' ? $team->captain : User::factory()->create();
        if (in_array($role, ['captain', 'member', 'gamemaster', 'admin'], true)) {
            OrganizationMember::factory()->create(['organization_id' => $org->id, 'user_id' => $actor->id, 'role' => in_array($role, ['captain', 'admin'], true) ? 'ORGANIZATION_ADMIN' : 'GAMEMASTER']);
        }
        if (in_array($role, ['member', 'gamemaster'], true)) {
            TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $actor->id]);
        }
        if ($role === 'super') {
            RoleAssignment::factory()->create(['user_id' => $actor->id]);
        }
        $gate = Gate::forUser($actor);
        $this->assertSame($update, $gate->allows('update', $team));
        foreach (['invites', 'transfer', 'archive'] as $ability) {
            $this->assertSame($support, $gate->allows($ability, $team));
        }
        $this->assertSame($remove, $gate->allows('remove', $team));
    }

    public function test_privileged_override_requires_reason_and_is_audited(): void
    {
        $team = Team::factory()->create();
        $admin = RoleAssignment::factory()->create()->user;
        try {
            app(TeamService::class)->execute($admin, 'archive', teamId: $team->public_id);
            $this->fail('Support override requires a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        app(TeamService::class)->execute($admin, 'archive', ['reason' => 'Owner recovery'], $team->public_id);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'action' => 'team.archived.v1', 'reason' => 'Owner recovery']);
    }

    public static function blockingSessionStates(): array
    {
        return [['REGISTRATION'], ['READY'], ['RUNNING'], ['PAUSED']];
    }

    #[DataProvider('blockingSessionStates')]
    public function test_team_archive_is_rejected_while_associated_session_is_active_or_pending(string $status): void
    {
        $team = Team::factory()->create();
        $session = GameSession::factory()->create(['status' => $status]);
        GameSessionTeam::factory()->create(['team_id' => $team->id, 'game_session_id' => $session->id]);
        $admin = RoleAssignment::factory()->create()->user;

        try {
            app(TeamService::class)->execute($admin, 'archive', ['reason' => 'Retire inactive team'], $team->public_id);
            $this->fail('A team linked to an active or pending session cannot be archived.');
        } catch (ApiException $exception) {
            $this->assertSame('STATE_TRANSITION_INVALID', $exception->errorCode);
        }

        $this->assertSame('ACTIVE', $team->fresh()->status);
        $this->assertDatabaseMissing('audit_events', ['action' => 'team.archived.v1']);
    }

    public function test_team_archive_remains_available_after_associated_session_is_terminal(): void
    {
        $team = Team::factory()->create();
        $session = GameSession::factory()->create(['status' => 'FINISHED']);
        GameSessionTeam::factory()->create(['team_id' => $team->id, 'game_session_id' => $session->id]);
        $admin = RoleAssignment::factory()->create()->user;

        app(TeamService::class)->execute($admin, 'archive', ['reason' => 'Retire completed team'], $team->public_id);

        $this->assertSame('ARCHIVED', $team->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'action' => 'team.archived.v1', 'reason' => 'Retire completed team']);
    }

    public function test_audit_failure_rolls_back_team_membership_and_outbox(): void
    {
        $actor = User::factory()->create();
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT team_test_audit_failure CHECK (action NOT LIKE 'team.%')");
        try {
            app(TeamService::class)->execute($actor, 'create', ['name' => 'Rollback']);
            $this->fail('The audit write must fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('team_members', 0);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertDatabaseCount('team_member_events', 0);
    }
}

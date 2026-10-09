<?php

namespace Tests\Feature;

use App\Models\GameSession;
use App\Models\OrganizationMember;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Games\CancellationResolution;
use App\Services\Games\GameMasterAssignments;
use App\Services\Games\GameScope;
use App\Services\Games\SessionService;
use App\Services\Games\UnavailableSessionDependencies;
use App\Services\Organizations\GlobalRoleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\GameSessionFixtures;
use Tests\TestCase;

class GameSessionSecurityTest extends TestCase
{
    use GameSessionFixtures,RefreshDatabase;

    public static function unauthorizedOperations(): array
    {
        return [['update'], ['open-registration'], ['close-registration'], ['start'], ['pause'], ['resume'], ['finish'], ['cancel']];
    }

    #[DataProvider('unauthorizedOperations')]
    public function test_participating_player_cannot_operate_session(string $action): void
    {
        $this->adapters();
        [,,$session] = $this->sessionFixture();
        $team = $this->registerFixture($session);
        $this->authenticateGame($team->captain);
        $this->withHeader('Idempotency-Key', 'player-operation');
        $url = '/api/v1/game-sessions/'.$session->public_id;
        if ($action === 'update') {
            $this->patchJson($url, ['timezone' => 'UTC'])->assertForbidden();
        } else {
            $this->postJson($url.'/'.$action, $action === 'cancel' ? ['reason' => 'Denied'] : [])->assertForbidden();
        }
        $this->assertSame('REGISTRATION', $session->fresh()->status);
    }

    public function test_superadmin_requires_reason_and_cannot_inherit_unlisted_session_operations(): void
    {
        $this->adapters();
        [$admin,$session] = $this->preparedFixture();
        $super = User::factory()->create();
        RoleAssignment::factory()->create(['user_id' => $super->id]);
        $this->authenticateGame($super);
        $url = '/api/v1/game-sessions/'.$session->public_id;
        $this->withHeader('Idempotency-Key', 'super-start')->postJson($url.'/start')->assertUnprocessable();
        $this->postJson($url.'/start', ['reason' => 'Approved recovery'])->assertOk();
        $this->withHeader('Idempotency-Key', 'super-finish')->postJson($url.'/finish', ['reason' => 'Not permitted by matrix'])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'super-pause')->postJson($url.'/pause', ['reason' => 'Emergency hold'])->assertOk();
        $this->withHeader('Idempotency-Key', 'super-cancel')->postJson($url.'/cancel', ['reason' => 'Emergency resolution', 'emergency' => true])->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'game_session.cancelled.v1', 'actor_id' => $super->id, 'reason' => 'Emergency resolution']);
    }

    public function test_unassigned_emergency_capability_is_not_implied_by_gamemaster_assignment(): void
    {
        $this->adapters();
        [$admin,$session] = $this->preparedFixture();
        $gm = User::factory()->create();
        OrganizationMember::factory()->create(['user_id' => $gm->id, 'organization_id' => $session->organization_id, 'role' => 'GAMEMASTER']);
        app(GameMasterAssignments::class)->assign($admin, $gm, $session->public_id, true, 'Assign session');
        app(SessionService::class)->execute($gm, 'start', [], $session->public_id, key: 'gm-start');
        $this->authenticateGame($gm);
        $this->withHeader('Idempotency-Key', 'gm-emergency')->postJson('/api/v1/game-sessions/'.$session->public_id.'/cancel', ['emergency' => true, 'reason' => 'Incident'])->assertForbidden();
        $this->assertSame('RUNNING', $session->fresh()->status);
    }

    public function test_assignment_cannot_reference_a_resource_in_another_organization_and_global_bootstrap_remains_available(): void
    {
        [$admin,$game,$session] = $this->sessionFixture(false);
        $gm = User::factory()->create();
        OrganizationMember::factory()->create(['user_id' => $gm->id, 'organization_id' => $game->organization_id, 'role' => 'GAMEMASTER']);
        app(GameMasterAssignments::class)->assign($admin, $gm, $session->public_id, true, 'Assign session');
        $other = GameSession::factory()->create();
        try {
            DB::transaction(fn () => DB::table('role_assignments')->where('user_id', $gm->id)->update(['resource_id' => $other->public_id]));
            $this->fail('Accepted a cross-tenant resource assignment.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $super = User::factory()->create();
        app(GlobalRoleService::class)->bootstrap($super->public_id, 'Initialize global administration after resource assignments');
        $this->assertDatabaseHas('role_assignments', ['user_id' => $super->id, 'role_code' => 'SUPER_ADMIN']);
    }

    public function test_organization_injection_archived_game_and_invalid_schedule_are_rejected(): void
    {
        [$admin,$game,$session] = $this->sessionFixture(false);
        $this->authenticateGame($admin);
        $this->postJson('/api/v1/game-sessions', ['game_id' => $game->public_id, 'organization_id' => $game->organization->public_id])->assertUnprocessable();
        $this->patchJson('/api/v1/game-sessions/'.$session->public_id, ['registration_opens_at' => '2030-01-02T00:00:00Z', 'registration_closes_at' => '2030-01-01T00:00:00Z'])->assertUnprocessable();
        $this->patchJson('/api/v1/game-sessions/'.$session->public_id, ['registration_closes_at' => '2030-01-02T00:00:00Z', 'scheduled_start_at' => '2030-01-01T00:00:00Z'])->assertUnprocessable();
        $this->postJson('/api/v1/games/'.$game->public_id.'/archive')->assertOk();
        $this->postJson('/api/v1/game-sessions', ['game_id' => $game->public_id])->assertConflict();
        $this->getJson('/api/v1/game-sessions/'.$session->public_id)->assertOk();
    }

    public function test_close_registration_requires_teams_and_external_preparation_without_success_event(): void
    {
        $fake = $this->adapters();
        [$admin,,$session] = $this->sessionFixture();
        $this->assertDomainError('SESSION_NOT_READY', fn () => app(SessionService::class)->execute($admin, 'closeRegistration', [], $session->public_id));
        $this->registerFixture($session);
        $fake->billing = 'PENDING';
        $this->assertDomainError('SESSION_BILLING_NOT_READY', fn () => app(SessionService::class)->execute($admin, 'closeRegistration', [], $session->public_id));
        $fake->billing = 'PASS';
        $fake->scenario = false;
        $this->assertDomainError('SCENARIO_NOT_ASSIGNED', fn () => app(SessionService::class)->execute($admin, 'closeRegistration', [], $session->public_id));
        $this->assertSame('REGISTRATION', $session->fresh()->status);
        $this->assertSame(0, DB::table('outbox_events')->where('topic', 'game_session.registration_closed.v1')->count());
    }

    public function test_production_cancellation_resolution_fails_closed_for_registered_and_running_sessions(): void
    {
        $this->adapters();
        [$admin,$session] = $this->preparedFixture();
        $this->app->instance(CancellationResolution::class, new UnavailableSessionDependencies);
        $this->assertDomainError('SESSION_NOT_READY', fn () => app(SessionService::class)->execute($admin, 'cancel', ['reason' => 'Cancel registration'], $session->public_id, key: 'pending-cancel'));
        app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'cancel-start');
        $this->assertDomainError('SESSION_NOT_READY', fn () => app(SessionService::class)->execute($admin, 'cancel', ['reason' => 'Incident', 'emergency' => true], $session->public_id, key: 'pending-emergency'));
        $this->assertSame('RUNNING', $session->fresh()->status);
    }

    public function test_revoked_device_token_is_rejected_and_fifth_ready_device_is_rechecked(): void
    {
        $this->adapters();
        [$admin,$session] = $this->preparedFixture(5, 5);
        $player = $session->players()->latest('id')->first();
        $device = $player->device;
        $this->authenticateGame($player->user, $device);
        $device->forceFill(['revoked_at' => now()])->save();
        $this->postJson('/api/v1/game-sessions/'.$session->public_id.'/ready', ['ready' => true])->assertUnauthorized();
        $this->assertDomainError('SESSION_NOT_READY', fn () => app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'five-ready-start'));
        $this->assertSame('READY', $session->fresh()->status);
    }

    public function test_all_five_teams_can_start_with_twenty_ready_players(): void
    {
        $fake = $this->adapters();
        [$admin,,$session] = $this->sessionFixture();
        for ($i = 0; $i < 5; $i++) {
            $this->registerFixture($session, 4);
        }
        $this->readyFixture($session);
        app(SessionService::class)->execute($admin, 'closeRegistration', [], $session->public_id);
        app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'five-team-start');
        $this->assertDatabaseCount('game_session_roster_snapshots', 5);
        $this->assertSame(20, $session->players()->where('participation_status', 'ACTIVE')->count());
        $this->assertSame(1, $fake->bootstrapCalls);
    }

    public function test_stale_scoped_query_rechecks_active_account_and_unknown_cursor_is_rejected(): void
    {
        [$actor,,$session] = $this->sessionFixture(false);
        $query = app(GameScope::class)->sessions($actor);
        $actor->forceFill(['status' => 'SUSPENDED'])->save();
        $this->assertDomainError('AUTHORIZATION_DENIED', fn () => $query->get());
        $actor->forceFill(['status' => 'ACTIVE'])->save();
        $this->authenticateGame($actor);
        $this->getJson('/api/v1/game-sessions?cursor=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/game-sessions/'.$session->public_id.'/timeline?cursor=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/game-sessions?sort=users.password_hash')->assertUnprocessable();
    }
}

<?php

namespace Tests\Feature;

use App\Models\GameSession;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Games\GameMasterAssignments;
use App\Services\Games\GameScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\GameSessionFixtures;
use Tests\TestCase;

class GameSessionApiTest extends TestCase
{
    use GameSessionFixtures,RefreshDatabase;

    public function test_authenticated_api_drives_session_creation_registration_ready_start_and_timeline(): void
    {
        $this->adapters();
        [$admin,$game] = $this->sessionFixture(false);
        $this->authenticateGame($admin);
        $response = $this->withHeader('X-Request-Id', 'session-create')->postJson('/api/v1/game-sessions', ['game_id' => $game->public_id, 'timezone' => 'Asia/Tehran'])->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $id = $response->json('data.id');
        $session = GameSession::where('public_id', $id)->sole();
        $this->patchJson('/api/v1/game-sessions/'.$id, ['scheduled_start_at' => '2030-10-10T12:00:00+03:30'])->assertOk()->assertJsonPath('data.scheduled_start_at', '2030-10-10T08:30:00.000000Z');
        $this->postJson('/api/v1/game-sessions/'.$id.'/open-registration')->assertOk()->assertJsonPath('data.status', 'REGISTRATION');
        $team = $this->teamFixture();
        $this->authenticateGame($team->captain);
        $this->postJson('/api/v1/game-sessions/'.$id.'/register-team', ['team_id' => $team->public_id])->assertOk()->assertJsonCount(4, 'data.teams.0.players');
        foreach ($session->players()->get() as $player) {
            $device = $this->authenticateGame($player->user);
            $this->postJson('/api/v1/game-sessions/'.$id.'/bind-device', ['device_id' => $device->public_id])->assertOk();
            $this->postJson('/api/v1/game-sessions/'.$id.'/ready', ['ready' => true])->assertOk();
        }
        $this->authenticateGame($admin);
        $this->postJson('/api/v1/game-sessions/'.$id.'/close-registration')->assertOk()->assertJsonPath('data.status', 'READY');
        $this->postJson('/api/v1/game-sessions/'.$id.'/start')->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'api-start')->postJson('/api/v1/game-sessions/'.$id.'/start')->assertOk()->assertJsonPath('data.session.status', 'RUNNING');
        $this->getJson('/api/v1/game-sessions/'.$id.'/state')->assertOk()->assertJsonPath('data.status', 'RUNNING');
        $timeline = $this->getJson('/api/v1/game-sessions/'.$id.'/timeline?limit=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.has_more', true);
        $this->getJson('/api/v1/game-sessions/'.$id.'/timeline?limit=2&cursor='.$timeline->json('meta.pagination.next_cursor'))->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('audit_events', ['action' => 'game_session.created.v1', 'request_id' => 'session-create', 'organization_id' => $game->organization_id]);
    }

    public function test_cross_tenant_lists_reads_and_direct_queries_do_not_leak_sessions(): void
    {
        $this->adapters();
        [$actor,,$own] = $this->sessionFixture();
        [,,$other] = $this->sessionFixture();
        $this->authenticateGame($actor);
        foreach (['', '/state', '/timeline'] as $suffix) {
            $this->getJson('/api/v1/game-sessions/'.$other->public_id.$suffix)->assertNotFound();
        }
        $this->postJson('/api/v1/game-sessions/'.$other->public_id.'/pause')->assertNotFound();
        $this->getJson('/api/v1/game-sessions')->assertOk()->assertJsonCount(1, 'data');
        $query = app(GameScope::class)->sessions($actor);
        $this->assertSame([$own->id], $query->pluck('id')->all());
        OrganizationMember::where('user_id', $actor->id)->update(['status' => 'REMOVED', 'left_at' => now()]);
        $this->assertFalse(app(GameScope::class)->operator($actor, $own));
    }

    public function test_unassigned_gamemaster_is_denied_and_scoped_assignment_can_be_revoked(): void
    {
        $this->adapters();
        [$admin,$game,$session] = $this->sessionFixture(false);
        $gm = User::factory()->create();
        OrganizationMember::factory()->create(['user_id' => $gm->id, 'organization_id' => $game->organization_id, 'role' => 'GAMEMASTER']);
        $this->authenticateGame($gm);
        $this->getJson('/api/v1/game-sessions/'.$session->public_id)->assertNotFound();
        app(GameMasterAssignments::class)->assign($admin, $gm, $session->public_id, true, 'Assign operator');
        $this->getJson('/api/v1/game-sessions/'.$session->public_id)->assertOk();
        $this->postJson('/api/v1/game-sessions/'.$session->public_id.'/open-registration')->assertOk();
        $this->postJson('/api/v1/game-sessions', ['game_id' => $game->public_id])->assertNotFound();
        app(GameMasterAssignments::class)->revoke($admin, $gm, $session->public_id, true, 'Shift ended');
        $this->getJson('/api/v1/game-sessions/'.$session->public_id)->assertNotFound();
    }

    public function test_player_projection_hides_other_rosters_and_operational_audit_details(): void
    {
        $this->adapters();
        [$admin,,$session] = $this->sessionFixture();
        $own = $this->registerFixture($session);
        $this->registerFixture($session);
        $this->authenticateGame($own->captain);
        $response = $this->getJson('/api/v1/game-sessions/'.$session->public_id)->assertOk();
        $teams = collect($response->json('data.teams'));
        $this->assertCount(4, $teams->firstWhere('team_id', $own->public_id)['players']);
        $this->assertSame([], $teams->firstWhere('team_id', '!=', $own->public_id)['players']);
        $response->assertJsonMissingPath('data.teams.0.players.0.user.email')->assertJsonMissingPath('data.created_by');
        $this->getJson('/api/v1/game-sessions/'.$session->public_id.'/timeline')->assertOk()->assertJsonMissingPath('data.0.reason')->assertJsonMissingPath('data.0.request_id');
        $this->withHeader('Idempotency-Key', 'captain-start')->postJson('/api/v1/game-sessions/'.$session->public_id.'/start')->assertForbidden();
    }

    public function test_device_binding_ready_unready_and_staff_rebind_are_safe_and_audited(): void
    {
        $this->adapters();
        [$admin,,$session] = $this->sessionFixture();
        $team = $this->registerFixture($session);
        $user = $team->captain;
        $device = $this->authenticateGame($user);
        $url = '/api/v1/game-sessions/'.$session->public_id;
        $this->postJson($url.'/ready', ['ready' => true])->assertConflict()->assertJsonPath('error.code', 'DEVICE_NOT_BOUND');
        $foreign = UserDevice::factory()->create();
        $this->postJson($url.'/bind-device', ['device_id' => $foreign->public_id])->assertConflict();
        $this->postJson($url.'/bind-device', ['device_id' => $device->public_id])->assertOk();
        $this->postJson($url.'/ready', ['ready' => true])->assertOk();
        $this->postJson($url.'/ready', ['ready' => true])->assertOk();
        $this->assertDatabaseCount('session_ready_events', 1);
        $this->postJson($url.'/ready', ['ready' => false])->assertOk();
        $this->assertDatabaseCount('session_ready_events', 2);
        $new = UserDevice::factory()->create(['user_id' => $user->id]);
        $this->authenticateGame($user, $new);
        $this->postJson($url.'/ready', ['ready' => true])->assertConflict()->assertJsonPath('error.code', 'DEVICE_NOT_BOUND');
        $this->authenticateGame($admin);
        $this->withHeader('Idempotency-Key', 'staff-rebind')->postJson($url.'/rebind-device', ['user_id' => $user->public_id, 'device_id' => $new->public_id])->assertUnprocessable();
        $payload = ['user_id' => $user->public_id, 'device_id' => $new->public_id, 'reason' => 'Replacement device'];
        $this->postJson($url.'/rebind-device', $payload)->assertOk();
        $this->postJson($url.'/rebind-device', $payload)->assertOk();
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'device.session_rebound.v1')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'device.session_rebound.v1', 'reason' => 'Replacement device', 'actor_id' => $admin->id]);
    }

    public static function unsafeFields(): array
    {
        return [['status', 'RUNNING'], ['organization_id', '00000000-0000-4000-8000-000000000000'], ['game_id', '00000000-0000-4000-8000-000000000000'], ['started_at', '2020-01-01'], ['timezone', 'Mars/Olympus'], ['paid', true]];
    }

    #[DataProvider('unsafeFields')]
    public function test_session_patch_denies_unsafe_fields(string $field, mixed $value): void
    {
        [$admin,,$session] = $this->sessionFixture(false);
        $this->authenticateGame($admin);
        $this->patchJson('/api/v1/game-sessions/'.$session->public_id, [$field => $value])->assertUnprocessable();
        $this->assertSame('DRAFT', $session->fresh()->status);
    }

    public function test_unauthenticated_routes_and_suspended_actor_are_rejected(): void
    {
        $this->getJson('/api/v1/games')->assertUnauthorized();
        $this->postJson('/api/v1/game-sessions', [])->assertUnauthorized();
        $user = User::factory()->create(['status' => 'SUSPENDED']);
        $this->authenticateGame($user);
        $this->getJson('/api/v1/game-sessions')->assertForbidden();
    }
}

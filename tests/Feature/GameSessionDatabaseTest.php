<?php

namespace Tests\Feature;

use App\Models\GameSession;
use App\Models\GameSessionPlayer;
use App\Models\GameSessionTeam;
use App\Models\TeamMember;
use App\Models\UserDevice;
use App\Services\Games\SessionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\GameSessionFixtures;
use Tests\TestCase;

class GameSessionDatabaseTest extends TestCase
{
    use GameSessionFixtures,RefreshDatabase;

    public function test_registration_capacity_eligibility_duplicate_team_and_user_are_enforced_without_partial_writes(): void
    {
        $this->adapters();
        [,,$session] = $this->sessionFixture();
        $small = $this->teamFixture(3);
        $this->assertDomainError('TEAM_NOT_ELIGIBLE', fn () => app(SessionService::class)->execute($small->captain, 'registerTeam', ['team_id' => $small->public_id], $session->public_id));
        $this->assertDatabaseCount('game_session_teams', 0);
        $first = $this->registerFixture($session, 8);
        $this->assertDomainError('CONFLICT', fn () => app(SessionService::class)->execute($first->captain, 'registerTeam', ['team_id' => $first->public_id], $session->public_id));
        $overlap = $this->teamFixture(4);
        TeamMember::factory()->create(['team_id' => $overlap->id, 'user_id' => $first->captain_user_id]);
        $this->assertDomainError('CONFLICT', fn () => app(SessionService::class)->execute($overlap->captain, 'registerTeam', ['team_id' => $overlap->public_id], $session->public_id));
        $this->assertDatabaseCount('game_session_teams', 1);
        $this->assertDatabaseCount('game_session_players', 8);
        for ($i = 0; $i < 4; $i++) {
            $this->registerFixture($session);
        }
        $sixth = $this->teamFixture();
        $this->assertDomainError('SESSION_TEAM_CAPACITY_REACHED', fn () => app(SessionService::class)->execute($sixth->captain, 'registerTeam', ['team_id' => $sixth->public_id], $session->public_id));
        $this->assertDatabaseCount('game_session_teams', 5);
        $this->assertDatabaseCount('game_session_players', 24);
    }

    public function test_team_removal_preserves_participation_and_persistent_membership(): void
    {
        $this->adapters();
        [,,$session] = $this->sessionFixture();
        $team = $this->registerFixture($session);
        $service = app(SessionService::class);
        $service->execute($team->captain, 'removeTeam', [], $session->public_id, $team->public_id, key: 'remove-team');
        $service->execute($team->captain, 'removeTeam', [], $session->public_id, $team->public_id, key: 'remove-team');
        $this->assertDatabaseHas('game_session_teams', ['status' => 'REMOVED']);
        $this->assertSame(4, $session->players()->where('participation_status', 'REMOVED')->count());
        $this->assertSame(4, $team->activeMembers()->count());
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'game_session.team_removed.v1')->count());
        $this->assertDomainError('CONFLICT', fn () => $service->execute($team->captain, 'registerTeam', ['team_id' => $team->public_id], $session->public_id));
    }

    public function test_registration_window_uses_server_time_and_operational_override_requires_reason(): void
    {
        $this->freezeTime();
        $this->adapters();
        [$admin,,$session] = $this->sessionFixture();
        app(SessionService::class)->execute($admin, 'update', ['registration_opens_at' => now()->addHour()->toISOString()], $session->public_id);
        $team = $this->teamFixture();
        try {
            app(SessionService::class)->execute($team->captain, 'registerTeam', ['team_id' => $team->public_id], $session->public_id);
            $this->fail('Window was bypassed');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('game_session_teams', 0);
        }
        app(SessionService::class)->execute($admin, 'registerTeam', ['team_id' => $team->public_id, 'reason' => 'Approved early enrollment'], $session->public_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'game_session.team_registered.v1', 'reason' => 'Approved early enrollment']);
    }

    public static function corruptions(): array
    {
        return [['same user'], ['same team'], ['wrong organization'], ['wrong roster session'], ['wrong device owner'], ['snapshot update'], ['snapshot delete'], ['transition update'], ['ready history delete'], ['frozen identity'], ['frozen add'], ['invalid status']];
    }

    #[DataProvider('corruptions')]
    public function test_database_rejects_invalid_ownership_duplicates_and_history_mutation(string $case): void
    {
        $this->adapters();
        [$admin,$session] = $this->preparedFixture();
        app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'database-start');
        $player = $session->players()->first();
        $registration = $session->teams()->first();
        $other = GameSession::factory()->create();
        $foreign = UserDevice::factory()->create();
        try {
            DB::transaction(function () use ($case, $session, $player, $registration, $other, $foreign): void {
                match ($case) {
                    'same user' => GameSessionPlayer::factory()->create(['game_session_id' => $session->id, 'game_session_team_id' => $registration->id, 'user_id' => $player->user_id]),
                    'same team' => GameSessionTeam::factory()->create(['game_session_id' => $session->id, 'team_id' => $registration->team_id]),
                    'wrong organization' => DB::table('game_sessions')->where('id', $session->id)->update(['organization_id' => $other->organization_id]),
                    'wrong roster session' => DB::table('game_session_players')->where('id', $player->id)->update(['game_session_id' => $other->id]),
                    'wrong device owner' => DB::table('game_session_players')->where('id', $player->id)->update(['active_device_id' => $foreign->id]),
                    'snapshot update' => DB::table('game_session_roster_snapshots')->update(['snapshot_json' => '{}']),
                    'snapshot delete' => DB::table('game_session_roster_snapshots')->delete(),
                    'transition update' => DB::table('game_session_state_transitions')->update(['to_state' => 'DRAFT']),
                    'ready history delete' => DB::table('session_ready_events')->delete(),
                    'frozen identity' => DB::table('game_session_players')->where('id', $player->id)->update(['roster_frozen_at' => null]),
                    'frozen add' => GameSessionPlayer::factory()->create(['game_session_id' => $session->id, 'game_session_team_id' => $registration->id]),
                    'invalid status' => DB::table('game_sessions')->where('id', $session->id)->update(['status' => 'REOPENED']),
                };
            });
            $this->fail('Database accepted invalid domain facts: '.$case);
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[0], ['23503', '23505', '23514']);
        }
        $this->assertSame('RUNNING', $session->fresh()->status);
        $this->assertDatabaseCount('game_session_roster_snapshots', 1);
    }
}

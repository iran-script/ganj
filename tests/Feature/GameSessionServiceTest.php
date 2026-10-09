<?php

namespace Tests\Feature;

use App\Models\GameSessionRosterSnapshot;
use App\Models\UserDevice;
use App\Services\Games\BillingGate;
use App\Services\Games\RuntimeBootstrap;
use App\Services\Games\ScenarioAssignmentReadiness;
use App\Services\Games\SessionService;
use App\Services\Games\UnavailableSessionDependencies;
use App\Services\Teams\TeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\GameSessionFixtures;
use Tests\TestCase;

class GameSessionServiceTest extends TestCase
{
    use GameSessionFixtures,RefreshDatabase;

    public function test_start_freezes_eight_players_with_exactly_four_ready_once_and_team_changes_do_not_rewrite_history(): void
    {
        $fake = $this->adapters();
        [$admin,$session,$team] = $this->preparedFixture(8);
        $service = app(SessionService::class);
        $first = $service->execute($admin, 'start', [], $session->public_id, key: 'start-once');
        $second = $service->execute($admin, 'start', [], $session->public_id, key: 'start-once');
        $this->assertEquals($first, $second);
        $this->assertSame('RUNNING', $session->fresh()->status);
        $this->assertSame(1, $fake->bootstrapCalls);
        $this->assertSame(['start:'.$session->public_id], $fake->commands);
        $this->assertDatabaseCount('game_session_roster_snapshots', 1);
        $this->assertSame(4, $session->players()->where('participation_status', 'ACTIVE')->count());
        $this->assertSame(4, $session->players()->where('participation_status', 'JOINED')->count());
        $before = GameSessionRosterSnapshot::sole()->snapshot_json;
        $this->assertCount(8, $before['players']);
        $this->assertCount(8, array_column($before['players'], 'billable_participation_ref'));
        $member = $team->activeMembers()->where('user_id', '!=', $team->captain_user_id)->first()->user;
        app(TeamService::class)->execute($member, 'leave', [], $team->public_id);
        $this->assertSame($before, GameSessionRosterSnapshot::sole()->snapshot_json);
        $this->assertSame(8, $session->players()->count());
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'game_session.started.v1')->count());
        $this->assertDomainError('IDEMPOTENCY_CONFLICT', fn () => $service->execute($admin, 'start', ['reason' => 'different'], $session->public_id, key: 'start-once'));
    }

    public static function dependencies(): array
    {
        return ['missing billable references' => ['participationReferences', false, 'SESSION_BILLING_NOT_READY'], 'billing fail' => ['billing', 'FAIL', 'SESSION_BILLING_NOT_READY'], 'billing pending' => ['billing', 'PENDING', 'SESSION_BILLING_NOT_READY'], 'scenario' => ['scenario', false, 'SCENARIO_NOT_ASSIGNED'], 'pricing' => ['pricing', false, 'SESSION_BILLING_NOT_READY'], 'runtime' => ['runtimeFailure', true, 'SESSION_NOT_READY']];
    }

    #[DataProvider('dependencies')]
    public function test_failed_start_rolls_back_snapshots_state_and_transactional_runtime_side_effects(string $field, mixed $value, string $code): void
    {
        $fake = $this->adapters();
        [$admin,$session] = $this->preparedFixture();
        $fake->{$field} = $value;
        $before = DB::table('game_session_state_transitions')->count();
        $this->assertDomainError($code, fn () => app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'failed-start'));
        $this->assertSame('READY', $session->fresh()->status);
        $this->assertNull($session->fresh()->started_at);
        $this->assertDatabaseCount('game_session_roster_snapshots', 0);
        $this->assertSame(0, $session->players()->whereNotNull('roster_frozen_at')->count());
        $this->assertSame($before, DB::table('game_session_state_transitions')->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'test.runtime.bootstrap')->count());
        $this->assertSame(0, DB::table('outbox_events')->where('topic', 'game_session.started.v1')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'game_session.start_failed.v1')->count());
        $fake->participationReferences = true;
        $fake->billing = 'PASS';
        $fake->scenario = true;
        $fake->pricing = true;
        $fake->runtimeFailure = false;
        app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'failed-start');
        $this->assertSame('RUNNING', $session->fresh()->status);
    }

    public static function productionPorts(): array
    {
        return [[BillingGate::class, 'SESSION_BILLING_NOT_READY'], [ScenarioAssignmentReadiness::class, 'SCENARIO_NOT_ASSIGNED'], [RuntimeBootstrap::class, 'SESSION_NOT_READY']];
    }

    #[DataProvider('productionPorts')]
    public function test_each_missing_production_start_adapter_fails_closed(string $port, string $code): void
    {
        $this->adapters();
        [$admin,$session] = $this->preparedFixture();
        $this->app->instance($port, new UnavailableSessionDependencies);
        $this->assertDomainError($code, fn () => app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'missing-adapter'));
        $this->assertSame('READY', $session->fresh()->status);
        $this->assertDatabaseCount('game_session_roster_snapshots', 0);
    }

    public function test_production_registration_open_fails_closed_without_pricing(): void
    {
        [$admin,,$session] = $this->sessionFixture(false);
        $this->assertDomainError('SESSION_BILLING_NOT_READY', fn () => app(SessionService::class)->execute($admin, 'openRegistration', [], $session->public_id));
        $this->assertSame('DRAFT', $session->fresh()->status);
    }

    public static function structuralFailures(): array
    {
        return [['three ready'], ['revoked device'], ['blocked player'], ['three roster']];
    }

    #[DataProvider('structuralFailures')]
    public function test_start_revalidates_roster_accounts_readiness_and_device_revocation(string $failure): void
    {
        $this->adapters();
        [$admin,$session,$team] = $this->preparedFixture(4, $failure === 'three ready' ? 3 : 4);
        $player = $session->players()->first();
        if ($failure === 'revoked device') {
            $player->device->forceFill(['revoked_at' => now()])->save();
        }
        if ($failure === 'blocked player') {
            $player->user->forceFill(['status' => 'BLOCKED'])->save();
        }
        if ($failure === 'three roster') {
            $ids = $team->activeMembers()->with('user')->get()->pluck('user.public_id')->take(3)->all();
            app(SessionService::class)->execute($team->captain, 'syncRoster', ['user_ids' => $ids], $session->public_id, $team->public_id);
        }
        $this->assertDomainError($failure === 'three roster' ? 'TEAM_NOT_ELIGIBLE' : 'SESSION_NOT_READY', fn () => app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'structural-start'));
        $this->assertSame('READY', $session->fresh()->status);
    }

    public function test_pause_resume_finish_append_history_and_terminals_cannot_reopen(): void
    {
        $this->adapters();
        [$admin,$session] = $this->preparedFixture();
        foreach (['start', 'pause', 'resume', 'pause', 'finish'] as $i => $action) {
            app(SessionService::class)->execute($admin, $action, [], $session->public_id, key: 'lifecycle-'.$i);
        }
        $this->assertSame('FINISHED', $session->fresh()->status);
        $this->assertNotNull($session->fresh()->finished_at);
        $this->assertSame(4, $session->players()->where('participation_status', 'FINISHED')->count());
        $this->assertSame(['REGISTRATION', 'READY', 'RUNNING', 'PAUSED', 'RUNNING', 'PAUSED', 'FINISHED'], DB::table('game_session_state_transitions')->orderBy('id')->pluck('to_state')->all());
        foreach (['openRegistration', 'start', 'resume', 'cancel', 'finish'] as $action) {
            $this->assertDomainError('STATE_TRANSITION_INVALID', fn () => app(SessionService::class)->execute($admin, $action, $action === 'cancel' ? ['reason' => 'terminal'] : [], $session->public_id, key: 'terminal-'.$action));
        }
    }

    public function test_prestart_cancel_is_reasoned_idempotent_and_terminal(): void
    {
        [$admin,,$session] = $this->sessionFixture(false);
        $input = ['reason' => 'Event cancelled'];
        $result = app(SessionService::class)->execute($admin, 'cancel', $input, $session->public_id, key: 'cancel-draft');
        $this->assertEquals($result, app(SessionService::class)->execute($admin, 'cancel', $input, $session->public_id, key: 'cancel-draft'));
        $this->assertSame('CANCELLED', $session->fresh()->status);
        $this->assertDatabaseHas('game_session_state_transitions', ['to_state' => 'CANCELLED', 'reason' => 'Event cancelled']);
        $this->assertDomainError('STATE_TRANSITION_INVALID', fn () => app(SessionService::class)->execute($admin, 'openRegistration', [], $session->public_id));
    }

    public function test_emergency_cancel_requires_flag_and_resolution_before_terminal_transition(): void
    {
        $fake = $this->adapters();
        [$admin,$session] = $this->preparedFixture();
        app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'emergency-start');
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => app(SessionService::class)->execute($admin, 'cancel', ['reason' => 'Incident'], $session->public_id, key: 'emergency-cancel'));
        $fake->cancellation = 'PENDING';
        $input = ['reason' => 'Incident', 'emergency' => true];
        $this->assertDomainError('SESSION_NOT_READY', fn () => app(SessionService::class)->execute($admin, 'cancel', $input, $session->public_id, key: 'emergency-cancel'));
        $this->assertSame('RUNNING', $session->fresh()->status);
        $fake->cancellation = 'RESOLVED';
        app(SessionService::class)->execute($admin, 'cancel', $input, $session->public_id, key: 'emergency-cancel');
        $this->assertSame('CANCELLED', $session->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'game_session.cancelled.v1', 'reason' => 'Incident', 'actor_id' => $admin->id]);
    }

    public function test_roster_only_late_join_uses_existing_frozen_player(): void
    {
        $this->adapters();
        [$admin,$session] = $this->preparedFixture(5);
        app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'late-start');
        $player = $session->players()->where('participation_status', 'JOINED')->sole();
        $device = UserDevice::factory()->create(['user_id' => $player->user_id]);
        app(SessionService::class)->execute($player->user, 'bindDevice', ['device_id' => $device->public_id], $session->public_id);
        app(SessionService::class)->execute($player->user, 'lateJoin', [], $session->public_id, requestDevice: $device);
        $this->assertSame('ACTIVE', $player->fresh()->participation_status);
        $this->assertDatabaseCount('game_session_players', 5);
        $this->assertDatabaseCount('game_session_roster_snapshots', 1);
    }
}

<?php

namespace Tests\Feature;

use App\Models\GameSession;
use App\Models\GameSessionPlayer;
use App\Models\GameSessionTeam;
use App\Models\RuntimeCommand;
use App\Models\RuntimeEvent;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Services\Audit\AuditService;
use App\Services\Games\BillingGate;
use App\Services\Games\RuntimeBootstrap;
use App\Services\Games\SessionService;
use App\Services\Games\UnavailableSessionDependencies;
use App\Services\Runtime\BarrierEvaluator;
use App\Services\Runtime\RuntimeEngine;
use App\Services\Runtime\RuntimeHandlers;
use App\Services\Runtime\RuntimeInitializer;
use App\Services\Runtime\RuntimeRoles;
use App\Services\Runtime\RuntimeTimers;
use App\Services\Runtime\ScoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class RuntimeEngineTest extends TestCase
{
    use RefreshDatabase, RuntimeFixtures;

    public function test_bootstrap_pins_published_version_and_is_retry_safe(): void
    {
        [$admin, $session, $instance, , , $version] = $this->runtimeFixture(teamCount: 2);
        $seed = $instance->random_seed;
        $before = RuntimeEvent::count();
        app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'runtime-start-'.$session->public_id);
        $this->assertDatabaseCount('team_scenario_instances', 2);
        $this->assertDatabaseCount('team_node_instances', 6);
        $this->assertSame($version->id, $instance->scenario_version_id);
        $this->assertSame($seed, $instance->fresh()->random_seed);
        $this->assertSame($before, RuntimeEvent::count());
        $this->assertNotSame(...TeamScenarioInstance::pluck('random_seed')->all());
        $this->assertDatabaseHas('team_node_instances', ['status' => 'AVAILABLE']);
        $rosters = $session->teams()->get()->map(fn ($t): array => ['team_id' => $t->team->public_id, 'scenario_version_ref' => $version->public_id])->all();
        DB::transaction(fn () => app(RuntimeInitializer::class)->bootstrap($session, $rosters, 'bootstrap-retry'));
        $this->assertDatabaseCount('team_scenario_instances', 2);
    }

    public function test_correct_evidence_completes_once_and_replays_original_result(): void
    {
        [, , , $player, $node] = $this->runtimeFixture();
        $this->runtimeAction($player, $node, 'start_node');
        $bad = $this->runtimeAction($player, $node, 'submit_action', ['answer' => 'wrong']);
        $this->assertFalse($bad['accepted']);
        $this->assertSame('ACTIVE', $node->fresh()->status);
        $first = $this->runtimeAction($player, $node, 'complete_node', ['answer' => 'Secret'], 'completion-key');
        $second = $this->runtimeAction($player, $node, 'complete_node', ['answer' => 'Secret'], 'completion-key');
        $this->assertSame($first, $second);
        $this->assertSame('COMPLETED', $node->fresh()->status);
        $this->assertSame(2, $node->fresh()->attempt_count);
        $this->assertSame(1, RuntimeEvent::where('aggregate_id', $node->public_id)->where('event_type', 'runtime.node_completed.v1')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('aggregate_id', $node->public_id)->where('topic', 'runtime.node_completed.v1')->count());
        $this->assertDomainError('IDEMPOTENCY_CONFLICT', fn () => $this->runtimeAction($player, $node, 'complete_node', ['answer' => 'other'], 'completion-key'));
        $this->assertDomainError('STATE_TRANSITION_INVALID', fn () => $this->runtimeAction($player, $node, 'complete_node', ['answer' => 'Secret']));
        $this->assertDatabaseHas('runtime_commands', ['idempotency_key' => 'client:'.hash('sha256', 'completion-key'), 'status' => 'SUCCEEDED']);
        $this->assertSame(1, RuntimeCommand::where('status', 'REJECTED')->where('command_type', 'submit_action')->count());
    }

    public function test_locked_node_cannot_start_and_finish_requires_valid_evidence(): void
    {
        [, $session, $instance, $player, $node] = $this->runtimeFixture();
        $finish = TeamNodeInstance::where('team_scenario_instance_id', $instance->id)->whereHas('node', fn ($q) => $q->where('node_type', 'Finish'))->sole();
        $this->assertDomainError('STATE_TRANSITION_INVALID', fn () => $this->runtimeAction($player, $finish, 'start_node'));
        $this->runtimeAction($player, $node, 'start_node');
        $this->runtimeAction($player, $node, 'submit_action', ['answer' => 'Secret']);
        $this->runtimeAction($player, $finish, 'start_node');
        $bad = $this->runtimeAction($player, $finish, 'complete_node');
        $this->assertFalse($bad['accepted']);
        $this->runtimeAction($player, $finish, 'complete_node', ['acknowledged' => true]);
        $this->assertSame('COMPLETED', $instance->fresh()->status);
        $this->assertNotNull($instance->fresh()->completed_at);
        $this->assertSame('RUNNING', $session->fresh()->status);
        $this->assertSame('FINISHED', $instance->registration->fresh()->status);
        $this->assertSame('FINISHED', $player->fresh()->participation_status);
    }

    public function test_billing_still_fails_closed_with_real_bootstrap(): void
    {
        $this->adapters();
        [$admin, $session] = $this->preparedFixture();
        $this->app->instance(BillingGate::class, new UnavailableSessionDependencies);
        $this->app->forgetInstance(RuntimeBootstrap::class);
        $this->app->bind(RuntimeBootstrap::class, RuntimeInitializer::class);
        $this->assertDomainError('SESSION_BILLING_NOT_READY', fn () => app(SessionService::class)->execute($admin, 'start', [], $session->public_id, key: 'billing-guard-key'));
        $this->assertDatabaseCount('team_scenario_instances', 0);
    }

    public function test_skip_requires_operator_and_reason_and_unlocks_next_path(): void
    {
        [$admin, , , $player, $node] = $this->runtimeFixture();
        $engine = app(RuntimeEngine::class);
        $this->assertDomainError('RESOURCE_NOT_FOUND', fn () => $this->runtimeAction($player, $node, 'skip_node', ['reason' => 'skip']));
        $this->assertDomainError('VALIDATION_FAILED', fn () => $engine->execute($admin, 'skip_node', $node->public_id, [], 'skip-missing-reason'));
        $engine->execute($admin, 'skip_node', $node->public_id, ['reason' => 'Field obstruction'], 'skip-node-key');
        $this->assertSame('SKIPPED', $node->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'runtime.node_skipped.v1', 'reason' => 'Field obstruction']);
    }

    public function test_exception_rolls_back_node_event_outbox_and_persists_failed_command(): void
    {
        [, , , $player, $node] = $this->runtimeFixture();
        $this->runtimeAction($player, $node, 'start_node');
        $before = RuntimeEvent::count();
        $this->app->bind(RuntimeHandlers::class, fn () => new class(app(RuntimeTimers::class), app(BarrierEvaluator::class), app(RuntimeRoles::class)) extends RuntimeHandlers
        {
            public function submit(TeamNodeInstance $node, ?GameSessionPlayer $player, array $input, string $commandId): array
            {
                $node->forceFill(['status' => 'COMPLETED'])->save();
                throw new \RuntimeException('Injected handler failure');
            }
        });
        $this->assertDomainError('INTERNAL_ERROR', fn () => $this->runtimeAction($player, $node, 'submit_action', ['answer' => 'Secret'], 'technical-failure'));
        $this->assertSame('ACTIVE', $node->fresh()->status);
        $this->assertSame($before, RuntimeEvent::count());
        $this->assertDatabaseHas('runtime_commands', ['idempotency_key' => 'client:'.hash('sha256', 'technical-failure'), 'status' => 'FAILED']);
    }

    public function test_partial_bootstrap_rolls_back_all_teams_events_and_outbox(): void
    {
        [, $original, , , , $version] = $this->runtimeFixture();
        $session = GameSession::factory()->create(['organization_id' => $original->organization_id, 'game_id' => $original->game_id, 'status' => 'READY']);
        $one = GameSessionTeam::factory()->create(['game_session_id' => $session->id]);
        $two = GameSessionTeam::factory()->create(['game_session_id' => $session->id]);
        $before = RuntimeEvent::count();
        $rosters = [['team_id' => $one->team->public_id, 'scenario_version_ref' => $version->public_id],
            ['team_id' => $two->team->public_id, 'scenario_version_ref' => (string) Str::uuid()]];
        $this->assertDomainError('SCENARIO_NOT_ASSIGNED', fn () => DB::transaction(fn () => app(RuntimeInitializer::class)->bootstrap($session, $rosters, 'partial-bootstrap')));
        $this->assertSame(0, TeamScenarioInstance::where('game_session_id', $session->id)->count());
        $this->assertSame($before, RuntimeEvent::count());
        $this->assertSame('READY', $session->fresh()->status);
    }

    public function test_future_action_adapter_receives_stable_command_and_cannot_repeat_side_effect(): void
    {
        [, , , $player, $node] = $this->runtimeFixture($this->runtimeGraph('Add Score', ['amount' => 10, 'reason' => 'Task reward']));
        $this->runtimeAction($player, $node, 'start_node');
        $adapter = new class implements ScoreAction
        {
            public array $commands = [];

            public function execute(TeamNodeInstance $node, ?GameSessionPlayer $player, array $evidence, string $commandId): array
            {
                $this->commands[] = $commandId;
                app(AuditService::class)->record('test.score.boundary', 'node_instance', $node->public_id);

                return ['accepted' => true, 'complete' => true];
            }
        };
        $this->app->instance(ScoreAction::class, $adapter);
        $one = $this->runtimeAction($player, $node, 'submit_action', [], 'score-boundary-key');
        $two = $this->runtimeAction($player, $node, 'submit_action', [], 'score-boundary-key');
        $this->assertSame($one, $two);
        $this->assertCount(1, $adapter->commands);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'test.score.boundary')->count());
    }
}

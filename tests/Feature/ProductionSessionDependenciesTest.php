<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameSession;
use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Models\Team;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Models\User;
use App\Services\Games\BillingGate;
use App\Services\Games\GameService;
use App\Services\Games\ProductionSessionDependencies;
use App\Services\Games\ScenarioAssignmentReadiness;
use App\Services\Games\SessionService;
use App\Services\Organizations\OperatorService;
use App\Services\Organizations\OrganizationService;
use App\Services\Scenarios\ScenarioGraph;
use App\Services\Scenarios\ScenarioService;
use App\Services\Teams\TeamService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\GameSessionFixtures;
use Tests\ScenarioFixtures;
use Tests\TestCase;

class ProductionSessionDependenciesTest extends TestCase
{
    use GameSessionFixtures, RefreshDatabase, ScenarioFixtures;

    private function configuredSession(string $mode = 'FREE'): array
    {
        $admin = User::factory()->create(['mobile' => '09121111111']);
        app(OperatorService::class)->grant($admin->mobile, 'Local production-adapter proof');
        $organization = app(OrganizationService::class)->create($admin, ['name' => 'Local proof', 'slug' => 'local-proof']);
        $scenarioData = app(ScenarioService::class)->execute($admin, 'create', ['organization_id' => $organization->public_id, 'title' => 'Local proof']);
        $scenario = Scenario::where('public_id', $scenarioData['id'])->sole();
        $versionData = app(ScenarioService::class)->execute($admin, 'createVersion', [], $scenario->public_id);
        $version = ScenarioVersion::where('public_id', $versionData['id'])->sole();
        $graph = ['nodes' => [
            ['node_key' => 'start', 'node_type' => 'Start', 'config' => []],
            ['node_key' => 'message', 'node_type' => 'Message', 'config' => ['text' => 'Welcome to the pilot']],
            ['node_key' => 'puzzle', 'node_type' => 'Puzzle', 'config' => ['prompt' => 'Enter NEXO', 'expected_answer' => 'NEXO', 'case_sensitive' => true, 'trim_whitespace' => true]],
            ['node_key' => 'finish', 'node_type' => 'Finish', 'config' => []]],
            'edges' => [['edge_key' => 'welcome', 'from_node' => 'start', 'to_node' => 'message', 'priority' => 0],
                ['edge_key' => 'challenge', 'from_node' => 'message', 'to_node' => 'puzzle', 'priority' => 0],
                ['edge_key' => 'complete', 'from_node' => 'puzzle', 'to_node' => 'finish', 'priority' => 0]],
            'variables' => [], 'conditions' => [], 'subflows' => []];
        app(ScenarioService::class)->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->revision_token], $version->public_id);
        $this->publishFixture($admin, $version);
        $data = app(GameService::class)->execute($admin, 'create', [
            'organization_id' => $scenario->organization->public_id, 'name' => 'Production admission',
            'slug' => 'production-admission', 'default_timezone' => 'UTC', 'status' => 'ACTIVE',
            'pricing' => ['mode' => $mode, 'amount' => $mode === 'FREE' ? 0 : 1000, 'currency' => 'IRR'],
            'scenario_version_id' => $version->public_id, 'reason' => 'Configure admission',
        ]);
        $game = Game::where('public_id', $data['id'])->sole();
        $data = app(SessionService::class)->execute($admin, 'create', ['game_id' => $game->public_id]);

        return [$admin, $game, GameSession::where('public_id', $data['id'])->sole(), $version->fresh()];
    }

    public function test_free_admission_runs_canonical_lifecycle_and_is_discoverable_without_test_adapters(): void
    {
        $this->freezeTime();
        [$admin, $game, $session, $version] = $this->configuredSession();
        $service = app(SessionService::class);
        $this->assertSame('DRAFT', $session->status);
        $service->execute($admin, 'openRegistration', [], $session->public_id);
        $this->assertSame('REGISTRATION', $session->fresh()->status);
        $teams = app(TeamService::class);
        $teamData = $teams->execute($admin, 'create', ['name' => 'Local proof team']);
        $team = Team::where('public_id', $teamData['id'])->sole();
        $invite = $teams->execute($admin, 'createInvite', ['max_uses' => 3], $team->public_id);
        foreach (User::factory()->count(3)->create() as $member) {
            $teams->execute($member, 'join', ['invite_code' => $invite['invite_code']]);
        }
        $service->execute($admin, 'registerTeam', ['team_id' => $team->public_id], $session->public_id);
        $this->readyFixture($session);
        $service->execute($admin, 'closeRegistration', [], $session->public_id);
        $this->assertSame('READY', $session->fresh()->status);

        $service->execute($admin, 'start', [], $session->public_id, key: 'real-start');

        $this->assertSame('RUNNING', $session->fresh()->status);
        $this->assertSame(now()->timestamp, $session->fresh()->started_at->timestamp);
        $this->assertDatabaseCount('session_configuration_snapshots', 1);
        $this->assertDatabaseHas('team_scenario_instances', ['game_session_id' => $session->id, 'scenario_version_id' => $version->id]);
        $this->assertDatabaseHas('outbox_events', ['topic' => 'game_session.started.v1']);
        $player = $session->players()->where('user_id', $team->captain_user_id)->sole();
        $this->authenticateGame($player->user, $player->device);
        $this->getJson('/api/v1/games')->assertOk()->assertJsonFragment(['id' => $game->public_id]);
        $this->getJson('/api/v1/game-sessions')->assertOk()->assertJsonFragment(['id' => $session->public_id]);
        $this->getJson('/api/v1/game-sessions/'.$session->public_id.'/players/me/runtime')->assertOk();
        $this->assertSame(1, TeamScenarioInstance::count());
        $instance = TeamScenarioInstance::sole();
        $nodes = TeamNodeInstance::with('node')->where('team_scenario_instance_id', $instance->id)->get()->keyBy(fn ($node): string => $node->node->node_key);
        $this->withHeader('Idempotency-Key', 'message-start')->postJson('/api/v1/runtime/nodes/'.$nodes['message']->public_id.'/start')->assertOk();
        $this->withHeader('Idempotency-Key', 'message-complete')->postJson('/api/v1/runtime/nodes/'.$nodes['message']->public_id.'/complete', ['acknowledged' => true])->assertOk();
        $this->withHeader('Idempotency-Key', 'puzzle-start')->postJson('/api/v1/runtime/nodes/'.$nodes['puzzle']->public_id.'/start')->assertOk();
        $this->withHeader('Idempotency-Key', 'wrong-answer')->postJson('/api/v1/runtime/nodes/'.$nodes['puzzle']->public_id.'/submit', ['answer' => 'wrong'])->assertOk()->assertJsonPath('data.accepted', false);
        $this->assertSame('ACTIVE', $nodes['puzzle']->fresh()->status);
        $this->withHeader('Idempotency-Key', 'correct-answer')->postJson('/api/v1/runtime/nodes/'.$nodes['puzzle']->public_id.'/submit', ['answer' => 'NEXO'])->assertOk()->assertJsonPath('data.accepted', true);
        $this->withHeader('Idempotency-Key', 'correct-answer')->postJson('/api/v1/runtime/nodes/'.$nodes['puzzle']->public_id.'/submit', ['answer' => 'NEXO'])->assertOk()->assertJsonPath('data.accepted', true);
        $this->withHeader('Idempotency-Key', 'finish-start')->postJson('/api/v1/runtime/nodes/'.$nodes['finish']->public_id.'/start')->assertOk();
        $this->withHeader('Idempotency-Key', 'finish-complete')->postJson('/api/v1/runtime/nodes/'.$nodes['finish']->public_id.'/complete', ['acknowledged' => true])->assertOk();
        $this->assertSame('COMPLETED', $instance->fresh()->status);
    }

    public function test_free_billing_is_an_idempotent_exemption_and_not_a_payment(): void
    {
        [$admin, , $session] = $this->configuredSession();
        app(SessionService::class)->execute($admin, 'openRegistration', [], $session->public_id);
        $billing = app(BillingGate::class);

        $first = $billing->evaluate($session, [], 'prepare');

        $this->assertSame('PASS', $first['status']);
        $this->assertStringStartsWith('free-exemption:', $first['reference']);
        $this->assertSame($first, $billing->evaluate($session, [], 'prepare'));
        $this->assertDatabaseCount('session_configuration_snapshots', 1);
    }

    public function test_running_session_keeps_v1_after_draft_edit_and_publish_while_new_session_uses_v2(): void
    {
        [$admin, $game, $session, $v1] = $this->configuredSession();
        $sessions = app(SessionService::class);
        $sessions->execute($admin, 'openRegistration', [], $session->public_id);
        $team = $this->registerFixture($session);
        $this->readyFixture($session);
        $sessions->execute($admin, 'closeRegistration', [], $session->public_id);
        $sessions->execute($admin, 'start', [], $session->public_id, key: 'version-one-start');
        $instance = TeamScenarioInstance::where('game_session_id', $session->id)->sole();
        $before = app(ScenarioGraph::class)->read($v1);
        $v2 = $v1->scenario->versions()->where('status', 'DRAFT')->sole();
        $changed = $before;
        $changed['nodes'][1]['config']['text'] = 'Second published message';
        app(ScenarioService::class)->execute($admin, 'saveGraph', $changed + ['revision_token' => $v2->revision_token], $v2->public_id);

        $this->publishFixture($admin, $v2);
        app(GameService::class)->execute($admin, 'update', ['scenario_version_id' => $v2->public_id], $game->public_id);

        $this->assertSame('RUNNING', $session->fresh()->status);
        $this->assertSame($v1->id, $instance->fresh()->scenario_version_id);
        $this->assertSame($before, app(ScenarioGraph::class)->read($instance->version));
        $player = $session->players()->where('user_id', $team->captain_user_id)->sole();
        $this->authenticateGame($player->user, $player->device);
        $this->getJson('/api/v1/game-sessions/'.$session->public_id.'/players/me/runtime')->assertOk()->assertJsonFragment(['scenario_version_id' => $v1->public_id]);
        $newData = $sessions->execute($admin, 'create', ['game_id' => $game->public_id]);
        $newSession = GameSession::where('public_id', $newData['id'])->sole();
        $sessions->execute($admin, 'openRegistration', [], $newSession->public_id);
        $this->registerFixture($newSession);
        $this->readyFixture($newSession);
        $sessions->execute($admin, 'closeRegistration', [], $newSession->public_id);
        $sessions->execute($admin, 'start', [], $newSession->public_id, key: 'version-two-start');
        $this->assertDatabaseHas('team_scenario_instances', ['game_session_id' => $newSession->id, 'scenario_version_id' => $v2->id]);
        $this->assertSame($v1->id, $instance->fresh()->scenario_version_id);
    }

    public function test_paid_pricing_is_resolved_but_unpaid_registration_is_blocked(): void
    {
        [$admin, , $session] = $this->configuredSession('PAID');
        $this->assertSame('PAID', app(ProductionSessionDependencies::class)->pricing($session)['mode']);

        $this->assertDomainError('SESSION_BILLING_NOT_READY', fn () => app(SessionService::class)->execute($admin, 'openRegistration', [], $session->public_id));

        $this->assertSame(['status' => 'PENDING', 'reference' => null], app(BillingGate::class)->evaluate($session, [], 'start'));
        $this->assertSame('DRAFT', $session->fresh()->status);
        $this->assertDatabaseCount('session_configuration_snapshots', 0);
    }

    public static function invalidPricing(): array
    {
        return ['missing' => [null], 'unknown' => [['mode' => 'OTHER', 'amount' => 0, 'currency' => 'IRR']],
            'free has charge' => [['mode' => 'FREE', 'amount' => 1, 'currency' => 'IRR']],
            'paid has no charge' => [['mode' => 'PAID', 'amount' => 0, 'currency' => 'IRR']],
            'invalid currency' => [['mode' => 'FREE', 'amount' => 0, 'currency' => 'bad']]];
    }

    #[DataProvider('invalidPricing')]
    public function test_invalid_pricing_fails_closed(?array $pricing): void
    {
        $this->assertDomainError('SESSION_BILLING_NOT_READY', fn () => app(ProductionSessionDependencies::class)->validatePricing($pricing));
    }

    public function test_missing_scenario_prevents_registration_and_assignment(): void
    {
        [$admin, $game, $session] = $this->configuredSession();
        $game->forceFill(['scenario_version_id' => null])->save();

        $this->assertDomainError('SESSION_BILLING_NOT_READY', fn () => app(SessionService::class)->execute($admin, 'openRegistration', [], $session->public_id));
        $this->assertDomainError('SCENARIO_NOT_ASSIGNED', fn () => app(ScenarioAssignmentReadiness::class)->assignments($session, []));
        $this->assertDatabaseCount('session_configuration_snapshots', 0);
    }

    public function test_draft_and_cross_tenant_scenarios_are_rejected_by_game_configuration(): void
    {
        [$admin, $game, , $version] = $this->configuredSession();
        $draft = $version->scenario->versions()->where('status', 'DRAFT')->sole();
        $this->assertDomainError('SCENARIO_NOT_ASSIGNED', fn () => app(GameService::class)->execute($admin, 'update', ['scenario_version_id' => $draft->public_id], $game->public_id));
        [$otherAdmin, , $otherVersion] = $this->scenarioFixture();
        $this->publishFixture($otherAdmin, $otherVersion);
        $this->assertDomainError('SCENARIO_NOT_ASSIGNED', fn () => app(GameService::class)->execute($admin, 'update', ['scenario_version_id' => $otherVersion->public_id], $game->public_id));
        $this->assertSame($version->id, $game->fresh()->scenario_version_id);
    }

    public function test_open_registration_pins_version_and_price_despite_later_game_changes(): void
    {
        [$admin, $game, $session, $version] = $this->configuredSession();
        app(SessionService::class)->execute($admin, 'openRegistration', [], $session->public_id);
        $newVersion = $version->scenario->versions()->where('status', 'DRAFT')->sole();
        app(ScenarioService::class)->execute($admin, 'saveGraph', $this->simpleGraph() + ['revision_token' => $newVersion->revision_token], $newVersion->public_id);
        $this->publishFixture($admin, $newVersion);
        app(GameService::class)->execute($admin, 'update', ['scenario_version_id' => $newVersion->public_id,
            'pricing' => ['mode' => 'PAID', 'amount' => 1000, 'currency' => 'IRR'], 'reason' => 'Future sessions'], $game->public_id);

        $this->assertSame(['team' => $version->public_id], app(ScenarioAssignmentReadiness::class)->assignments($session, [['team_id' => 'team']]));
        $this->assertSame('FREE', app(ProductionSessionDependencies::class)->pricing($session)['mode']);
    }

    public function test_configuration_snapshot_cannot_be_rewritten(): void
    {
        [$admin, , $session] = $this->configuredSession();
        app(SessionService::class)->execute($admin, 'openRegistration', [], $session->public_id);

        try {
            DB::transaction(fn () => DB::table('session_configuration_snapshots')->where('game_session_id', $session->id)->update(['pricing' => '{}']));
            $this->fail('An admission snapshot must remain immutable.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }

        $this->assertSame('FREE', app(ProductionSessionDependencies::class)->pricing($session)['mode']);
    }

    public function test_archived_game_cannot_open_registration(): void
    {
        [$admin, $game, $session] = $this->configuredSession();
        app(GameService::class)->execute($admin, 'archive', ['reason' => 'Retired content'], $game->public_id);

        $this->assertDomainError('STATE_TRANSITION_INVALID', fn () => app(SessionService::class)->execute($admin, 'openRegistration', [], $session->public_id));

        $this->assertSame('DRAFT', $session->fresh()->status);
        $this->assertDatabaseCount('session_configuration_snapshots', 0);
    }

    public function test_free_cancellation_requires_no_refund_and_uses_canonical_transition(): void
    {
        [$admin, , $session] = $this->configuredSession();
        $service = app(SessionService::class);
        $service->execute($admin, 'openRegistration', [], $session->public_id);
        $this->registerFixture($session);

        $service->execute($admin, 'cancel', ['reason' => 'Cancel event'], $session->public_id, key: 'cancel-free');

        $this->assertSame('CANCELLED', $session->fresh()->status);
        $this->assertDatabaseCount('session_configuration_snapshots', 1);
    }

    public function test_insufficient_team_cannot_register(): void
    {
        [$admin, , $session] = $this->configuredSession();
        app(SessionService::class)->execute($admin, 'openRegistration', [], $session->public_id);

        $this->assertDomainError('TEAM_NOT_ELIGIBLE', fn () => $this->registerFixture($session, 3));

        $this->assertDatabaseCount('game_session_teams', 0);
    }
}

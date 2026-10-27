<?php

namespace Tests\Feature;

use App\Models\GameSession;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Models\User;
use App\Services\Games\GameService;
use App\Services\Games\RuntimeBootstrap;
use App\Services\Games\ScenarioAssignmentReadiness;
use App\Services\Runtime\RuntimeInitializer;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\GameSessionFixtures;
use Tests\ScenarioFixtures;
use Tests\TestCase;

class GameJourneyApiTest extends TestCase
{
    use GameSessionFixtures, RefreshDatabase, ScenarioFixtures;

    public function test_registered_players_complete_a_scored_game_journey_through_the_api(): void
    {
        $this->adapters();
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        OrganizationMember::factory()->administrator()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
        ]);

        $scenario = Scenario::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
        ]);
        $version = ScenarioVersion::factory()->create(['scenario_id' => $scenario->id]);

        $this->scenarioAuth($admin);
        $qr = $this->postJson('/api/v1/qr/triggers', ['organization_id' => $organization->public_id])
            ->assertCreated()->json('data');

        $graph = [
            'nodes' => [
                ['node_key' => 'start', 'node_type' => 'Start', 'config' => []],
                ['node_key' => 'gps', 'node_type' => 'GPS / Location', 'config' => ['latitude' => 35.6892, 'longitude' => 51.389, 'radius_m' => 50]],
                ['node_key' => 'qr', 'node_type' => 'QR', 'config' => ['trigger_id' => $qr['trigger']['id']]],
                ['node_key' => 'score', 'node_type' => 'Score', 'config' => ['operation' => 'ADD', 'amount' => 500, 'reason' => 'Completed mission']],
                ['node_key' => 'finish', 'node_type' => 'Finish', 'config' => []],
            ],
            'edges' => [
                ['edge_key' => 'start-gps', 'from_node' => 'start', 'to_node' => 'gps', 'priority' => 0],
                ['edge_key' => 'gps-qr', 'from_node' => 'gps', 'to_node' => 'qr', 'priority' => 0],
                ['edge_key' => 'qr-score', 'from_node' => 'qr', 'to_node' => 'score', 'priority' => 0],
                ['edge_key' => 'score-finish', 'from_node' => 'score', 'to_node' => 'finish', 'priority' => 0],
            ],
            'variables' => [],
            'conditions' => [],
            'subflows' => [],
        ];
        app(ScenarioService::class)->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->revision_token], $version->public_id);
        $this->publishFixture($admin, $version->fresh());

        $game = app(GameService::class)->execute($admin, 'create', [
            'organization_id' => $organization->public_id,
            'name' => 'API Journey',
            'slug' => 'api-journey',
            'default_timezone' => 'UTC',
            'status' => 'ACTIVE',
            'pricing' => ['mode' => 'FREE', 'amount' => 0, 'currency' => 'IRR'],
            'scenario_version_id' => $version->public_id,
            'reason' => 'Exercise the implemented gameplay APIs',
        ]);
        $this->app->instance(ScenarioAssignmentReadiness::class, new class($version->public_id) implements ScenarioAssignmentReadiness
        {
            public function __construct(private string $versionId) {}

            public function assignments(GameSession $session, array $roster): array
            {
                return array_fill_keys(array_column($roster, 'team_id'), $this->versionId);
            }
        });
        $this->app->forgetInstance(RuntimeBootstrap::class);
        $this->app->bind(RuntimeBootstrap::class, RuntimeInitializer::class);

        $players = [];
        for ($index = 1; $index <= 4; $index++) {
            $players[] = $this->registerPlayer((string) Str::uuid(), 'journey-player-'.$index.'@example.test');
        }

        $captain = $players[0];
        $this->asIdentity($captain['token'], $captain['device_uuid']);
        $teamId = $this->postJson('/api/v1/teams', ['name' => 'Journey Team'])->assertCreated()->json('data.id');
        $inviteCode = $this->postJson('/api/v1/teams/'.$teamId.'/invites', ['max_uses' => 3])->assertCreated()->json('data.invite_code');
        foreach (array_slice($players, 1) as $player) {
            $this->asIdentity($player['token'], $player['device_uuid']);
            $requestId = $this->postJson('/api/v1/teams/join', ['invite_code' => $inviteCode])
                ->assertOk()->assertJsonPath('data.status', 'PENDING')->json('data.id');
            $this->asIdentity($captain['token'], $captain['device_uuid']);
            $this->postJson('/api/v1/teams/'.$teamId.'/join-requests/'.$requestId.'/accept')
                ->assertOk()->assertJsonPath('data.status', 'ACCEPTED');
        }

        $this->authenticateGame($admin);
        $sessionId = $this->postJson('/api/v1/game-sessions', ['game_id' => $game['id'], 'timezone' => 'UTC'])
            ->assertCreated()->json('data.id');
        $session = GameSession::where('public_id', $sessionId)->sole();
        $this->postJson('/api/v1/game-sessions/'.$sessionId.'/open-registration')->assertOk();
        $this->asIdentity($captain['token'], $captain['device_uuid']);
        $this->postJson('/api/v1/game-sessions/'.$sessionId.'/register-team', ['team_id' => $teamId])->assertOk();

        foreach ($players as $player) {
            $this->asIdentity($player['token'], $player['device_uuid']);
            $this->postJson('/api/v1/game-sessions/'.$sessionId.'/bind-device', ['device_id' => $player['device']['id']])->assertOk();
            $this->postJson('/api/v1/game-sessions/'.$sessionId.'/ready', ['ready' => true])->assertOk();
        }
        $this->authenticateGame($admin);
        $this->postJson('/api/v1/game-sessions/'.$sessionId.'/close-registration')->assertOk();
        $this->withHeader('Idempotency-Key', 'journey-start')->postJson('/api/v1/game-sessions/'.$sessionId.'/start')->assertOk();

        $instance = TeamScenarioInstance::where('game_session_id', $session->id)->sole();
        $nodes = TeamNodeInstance::with('node')->where('team_scenario_instance_id', $instance->id)
            ->get()->keyBy(fn (TeamNodeInstance $node): string => $node->node->node_key);
        $this->asIdentity($captain['token'], $captain['device_uuid']);
        $runtime = $this->getJson('/api/v1/game-sessions/'.$sessionId.'/players/me/runtime')->assertOk();
        $this->assertSame('ACTIVE', $runtime->json('data.status'));
        $this->assertSame('AVAILABLE', collect($runtime->json('data.nodes'))->firstWhere('node_key', 'gps')['status']);

        $this->withHeader('Idempotency-Key', 'journey-gps-start')->postJson('/api/v1/runtime/nodes/'.$nodes['gps']->public_id.'/start')->assertOk();
        $this->withHeader('X-Device-Id', $captain['device_uuid'])->postJson('/api/v1/locations', [
            'session_id' => $sessionId,
            'lat' => 35.6892,
            'lng' => 51.389,
            'accuracy' => 3,
            'client_timestamp' => now()->startOfSecond()->toISOString(),
            'sequence' => 1,
        ])->assertOk();
        $this->withHeader('Idempotency-Key', 'journey-gps-complete')->postJson('/api/v1/runtime/nodes/'.$nodes['gps']->public_id.'/complete')->assertOk()->assertJsonPath('data.complete', true);

        $this->withHeader('Idempotency-Key', 'journey-qr-start')->postJson('/api/v1/runtime/nodes/'.$nodes['qr']->public_id.'/start')->assertOk();
        $scan = $this->withHeader('Idempotency-Key', 'journey-qr-scan')->postJson('/api/v1/qr/scan', [
            'session_id' => $sessionId,
            'qr_identifier' => $qr['identifier'],
        ])->assertOk()->json('data');
        $this->assertTrue($scan['accepted']);
        $this->withHeader('Idempotency-Key', 'journey-qr-complete')->postJson('/api/v1/runtime/nodes/'.$nodes['qr']->public_id.'/complete', [
            'qr_token' => $scan['one_time_token'],
        ])->assertOk()->assertJsonPath('data.complete', true);

        $this->assertSame('COMPLETED', $nodes['score']->fresh()->status);
        $this->withHeader('Idempotency-Key', 'journey-finish-start')->postJson('/api/v1/runtime/nodes/'.$nodes['finish']->public_id.'/start')->assertOk();
        $this->withHeader('Idempotency-Key', 'journey-finish-complete')->postJson('/api/v1/runtime/nodes/'.$nodes['finish']->public_id.'/complete', ['acknowledged' => true])->assertOk()->assertJsonPath('data.complete', true);
        $this->assertSame('COMPLETED', $instance->fresh()->status);
        $this->authenticateGame($admin);
        $this->assertSame(500, (int) $this->getJson('/api/v1/game-sessions/'.$sessionId.'/teams/'.$teamId.'/score')->assertOk()->json('data.score'));
        $this->withHeader('Idempotency-Key', 'journey-session-finish')->postJson('/api/v1/game-sessions/'.$sessionId.'/finish', ['reason' => 'Journey completed'])->assertOk();
        $leaderboard = $this->getJson('/api/v1/game-sessions/'.$sessionId.'/leaderboard')->assertOk();
        $this->assertSame($teamId, $leaderboard->json('data.rankings.0.team_id'));
        $this->assertSame(500, (int) $leaderboard->json('data.rankings.0.score'));
        $this->assertSame('FINISHED', $session->fresh()->status);
    }

    /** @return array{token:string,device_uuid:string,device:array<string,mixed>} */
    private function registerPlayer(string $deviceUuid, string $email): array
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Journey Player',
            'email' => $email,
            'password' => 'Strong-password-123!',
            'device_uuid' => $deviceUuid,
            'device_name' => 'Journey Device',
            'platform' => 'android',
            'app_version' => '1.0',
        ])->assertCreated()->json('data');

        return ['token' => $response['token'], 'device_uuid' => $deviceUuid, 'device' => $response['device']];
    }

    private function asIdentity(string $token, string $deviceUuid): void
    {
        $this->withToken($token)->withHeader('X-Device-Id', $deviceUuid);
    }
}

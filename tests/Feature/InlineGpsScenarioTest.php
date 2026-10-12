<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Checkpoint;
use App\Models\GameSession;
use App\Models\QrTrigger;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Services\Games\GameService;
use App\Services\Games\SessionService;
use App\Services\Qr\QrService;
use App\Services\Scenarios\GraphValidator;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\LocationFixtures;
use Tests\TestCase;

class InlineGpsScenarioTest extends TestCase
{
    use LocationFixtures, RefreshDatabase;

    public static function invalidCoordinates(): array
    {
        return ['latitude missing' => [['longitude' => 51.389, 'radius_m' => 50]], 'latitude invalid' => [['latitude' => 91, 'longitude' => 51.389, 'radius_m' => 50]],
            'longitude invalid' => [['latitude' => 35.6892, 'longitude' => 181, 'radius_m' => 50]], 'radius invalid' => [['latitude' => 35.6892, 'longitude' => 51.389, 'radius_m' => 0]],
            'longitude missing' => [['latitude' => 35, 'radius_m' => 50]], 'radius missing' => [['latitude' => 35, 'longitude' => 51]],
            'accuracy invalid' => [['latitude' => 35, 'longitude' => 51, 'radius_m' => 50, 'accuracy_m' => 0]],
            'negative dwell' => [['latitude' => 35, 'longitude' => 51, 'radius_m' => 50, 'dwell_seconds' => -1]],
            'fractional dwell' => [['latitude' => 35, 'longitude' => 51, 'radius_m' => 50, 'dwell_seconds' => 0.5]]];
    }

    #[DataProvider('invalidCoordinates')]
    public function test_inline_gps_validation_rejects_invalid_coordinates(array $config): void
    {
        [, $scenario] = $this->scenarioFixture(false);
        $graph = $this->simpleGraph();
        $graph['nodes'][0] = ['node_key' => 'start', 'node_type' => 'GPS / Location', 'config' => $config];
        $result = app(GraphValidator::class)->validate($graph, $scenario->organization_id);
        $this->assertSame('FAIL', $result['status']);
        $this->assertContains('INVALID_NODE_CONFIG', array_column($result['errors'], 'code'));
    }

    public function test_inline_gps_graph_publishes_without_checkpoint_and_remains_immutable(): void
    {
        [$admin, , $version] = $this->scenarioFixture(false);
        $graph = $this->simpleGraph();
        $graph['nodes'][0] = ['node_key' => 'start', 'node_type' => 'Start', 'config' => []];
        $graph['nodes'][1] = ['node_key' => 'gps', 'node_type' => 'GPS / Location', 'title' => 'موقعیت', 'config' => ['latitude' => 35.6892, 'longitude' => 51.389, 'radius_m' => 50]];
        $graph['nodes'][] = ['node_key' => 'finish', 'node_type' => 'Finish', 'config' => []];
        $graph['edges'] = [['edge_key' => 'one', 'from_node' => 'start', 'to_node' => 'gps', 'priority' => 0], ['edge_key' => 'two', 'from_node' => 'gps', 'to_node' => 'finish', 'priority' => 0]];
        $service = app(ScenarioService::class);
        $saved = $service->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->revision_token], $version->public_id);
        $this->assertSame('PASS', $saved['validation']['status']);
        $published = $this->publishFixture($admin, $version->fresh());
        $this->assertSame('PUBLISHED', $published['status']);
        $this->expectException(ApiException::class);
        $service->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->fresh()->revision_token, 'nodes' => array_replace($graph['nodes'], [['node_key' => 'start', 'node_type' => 'Start', 'config' => []], ['node_key' => 'gps', 'node_type' => 'GPS / Location', 'config' => ['latitude' => 36, 'longitude' => 51.389, 'radius_m' => 50]], ['node_key' => 'finish', 'node_type' => 'Finish', 'config' => []]])], $version->public_id);
    }

    public function test_runtime_uses_inline_coordinates_and_postgis_distance(): void
    {
        [, $session, , $player, $node] = $this->locationFixture(gpsConfig: ['latitude' => 35.6892, 'longitude' => 51.389, 'radius_m' => 50, 'accuracy_m' => 10, 'dwell_seconds' => 0]);
        $this->ingest($player, $this->sample($session));
        $this->assertTrue($this->runtimeAction($player, $node, 'complete_node')['complete']);
    }

    public function test_full_acceptance_graph_publishes_with_inline_gps_and_qr_resource(): void
    {
        $this->freezeTime();
        [$admin, $scenario, $version] = $this->scenarioFixture(false);
        $resource = app(QrService::class)->create($admin, ['organization_id' => $scenario->organization->public_id]);
        $trigger = QrTrigger::where('public_id', $resource['trigger']['id'])->sole();
        $graph = ['nodes' => [
            ['node_key' => 'start', 'node_type' => 'Start', 'config' => []],
            ['node_key' => 'message', 'node_type' => 'Message', 'config' => ['text' => 'راهنما']],
            ['node_key' => 'gps', 'node_type' => 'GPS / Location', 'config' => ['latitude' => 35.6892, 'longitude' => 51.389, 'radius_m' => 50]],
            ['node_key' => 'qr', 'node_type' => 'QR', 'config' => ['trigger_id' => $trigger->public_id]],
            ['node_key' => 'puzzle', 'node_type' => 'Puzzle', 'config' => ['prompt' => '۲ + ۲؟', 'expected_answer' => '4', 'case_sensitive' => false, 'trim_whitespace' => true]],
            ['node_key' => 'finish', 'node_type' => 'Finish', 'config' => []],
        ], 'edges' => [
            ['edge_key' => 'one', 'from_node' => 'start', 'to_node' => 'message', 'priority' => 0],
            ['edge_key' => 'two', 'from_node' => 'message', 'to_node' => 'gps', 'priority' => 0],
            ['edge_key' => 'three', 'from_node' => 'gps', 'to_node' => 'qr', 'priority' => 0],
            ['edge_key' => 'four', 'from_node' => 'qr', 'to_node' => 'puzzle', 'priority' => 0],
            ['edge_key' => 'five', 'from_node' => 'puzzle', 'to_node' => 'finish', 'priority' => 0],
        ], 'variables' => [], 'conditions' => [], 'subflows' => []];
        $service = app(ScenarioService::class);
        $saved = $service->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->revision_token], $version->public_id);
        $this->assertSame('PASS', $saved['validation']['status']);
        $this->assertSame('PUBLISHED', $this->publishFixture($admin, $version->fresh())['status']);

        $game = app(GameService::class)->execute($admin, 'create', [
            'organization_id' => $scenario->organization->public_id, 'name' => 'Acceptance', 'slug' => 'acceptance',
            'default_timezone' => 'UTC', 'status' => 'ACTIVE', 'pricing' => ['mode' => 'FREE', 'amount' => 0, 'currency' => 'IRR'],
            'scenario_version_id' => $version->public_id, 'reason' => 'Verify acceptance graph',
        ]);
        $sessions = app(SessionService::class);
        $created = $sessions->execute($admin, 'create', ['game_id' => $game['id']]);
        $session = GameSession::where('public_id', $created['id'])->sole();
        $sessions->execute($admin, 'openRegistration', [], $session->public_id);
        $this->registerFixture($session);
        $this->readyFixture($session);
        $sessions->execute($admin, 'closeRegistration', [], $session->public_id);
        $sessions->execute($admin, 'start', [], $session->public_id, key: 'acceptance-start');
        $instance = TeamScenarioInstance::where('game_session_id', $session->id)->sole();
        $this->assertSame($version->id, $instance->scenario_version_id);
        $player = $instance->registration->players()->orderBy('id')->firstOrFail();
        $nodes = TeamNodeInstance::with('node')->where('team_scenario_instance_id', $instance->id)->get()->keyBy(fn ($node): string => $node->node->node_key);
        $this->assertSame('COMPLETED', $nodes['start']->status);
        $this->runtimeAction($player, $nodes['message'], 'start_node');
        $this->assertTrue($this->runtimeAction($player, $nodes['message'], 'complete_node', ['acknowledged' => true])['complete']);
        $this->runtimeAction($player, $nodes['gps'], 'start_node');
        $this->ingest($player, $this->sample($session));
        $this->assertTrue($this->runtimeAction($player, $nodes['gps'], 'complete_node')['complete']);
        $this->assertDatabaseHas('geofence_evaluations', ['node_instance_id' => $nodes['gps']->id, 'checkpoint_id' => null, 'result' => 'INSIDE']);
        $this->runtimeAction($player, $nodes['qr'], 'start_node');
        $scan = app(QrService::class)->scan($player->user, $player->device, ['session_id' => $session->public_id, 'qr_identifier' => $resource['identifier']], 'acceptance-scan');
        $this->assertTrue($scan['accepted']);
        $this->assertTrue($this->runtimeAction($player, $nodes['qr'], 'complete_node', ['qr_token' => $scan['one_time_token']])['complete']);
        $this->runtimeAction($player, $nodes['puzzle'], 'start_node');
        $this->assertTrue($this->runtimeAction($player, $nodes['puzzle'], 'complete_node', ['answer' => '4'])['complete']);
        $this->runtimeAction($player, $nodes['finish'], 'start_node');
        $this->assertTrue($this->runtimeAction($player, $nodes['finish'], 'complete_node', ['acknowledged' => true])['complete']);
        $this->assertSame('COMPLETED', $instance->fresh()->status);
        $this->assertSame(6, $instance->nodes()->where('status', 'COMPLETED')->count());
        $this->assertDatabaseCount('checkpoints', 0);
        $this->assertDomainError('STATE_TRANSITION_INVALID', fn () => $service->execute($admin, 'saveGraph', array_replace($graph, ['revision_token' => $version->fresh()->revision_token]), $version->public_id));
    }

    public static function inlineEvidence(): array
    {
        return ['inside' => [[], 'INSIDE', true], 'outside' => [['lat' => 35.70], 'OUTSIDE', false],
            'poor accuracy' => [['accuracy' => 31], 'UNCERTAIN', false], 'boundary uncertainty' => [['lat' => 35.68965, 'accuracy' => 10], 'UNCERTAIN', false]];
    }

    #[DataProvider('inlineEvidence')]
    public function test_inline_gps_classifies_real_postgis_evidence(array $changes, string $classification, bool $complete): void
    {
        $this->freezeTime();
        [, $session, , $player, $node] = $this->locationFixture(gpsConfig: ['latitude' => 35.6892, 'longitude' => 51.389, 'radius_m' => 50]);
        $this->ingest($player, $this->sample($session, changes: $changes));

        $this->assertSame($complete, $this->runtimeAction($player, $node, 'complete_node')['complete']);
        $evaluation = DB::table('geofence_evaluations')->where('node_instance_id', $node->id)->sole();
        $this->assertSame($classification, $evaluation->result);
        $this->assertNull($evaluation->checkpoint_id);
        $this->assertGreaterThanOrEqual(0, (float) $evaluation->distance_meters);
    }

    public function test_inline_dwell_requires_fresh_repeated_evidence(): void
    {
        $this->freezeTime();
        [, $session, , $player, $node] = $this->locationFixture(gpsConfig: ['latitude' => 35.6892, 'longitude' => 51.389, 'radius_m' => 50, 'accuracy_m' => null, 'dwell_seconds' => 10]);
        $this->ingest($player, $this->sample($session));
        $this->travel(10)->seconds();
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
        $this->ingest($player, $this->sample($session, 2));
        $this->assertTrue($this->runtimeAction($player, $node, 'complete_node')['complete']);
    }

    public function test_legacy_checkpoint_coordinates_are_normalized_into_the_published_graph(): void
    {
        [$admin, $scenario, $version] = $this->scenarioFixture(false);
        $checkpoint = Checkpoint::factory()->create(['organization_id' => $scenario->organization_id]);
        $graph = $this->runtimeGraph('GPS / Location', ['checkpoint_id' => $checkpoint->public_id]);

        $saved = app(ScenarioService::class)->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->revision_token], $version->public_id);

        $this->assertSame('PASS', $saved['validation']['status']);
        $config = collect($saved['graph']['nodes'])->firstWhere('node_key', 'task')['config'];
        $this->assertEqualsWithDelta(35.6892, $config['latitude'], 0.000001);
        $this->assertEqualsWithDelta(51.389, $config['longitude'], 0.000001);
        $this->assertSame(50.0, (float) $config['radius_m']);
        $this->assertSame('PUBLISHED', $this->publishFixture($admin, $version->fresh())['status']);
    }

    public static function unsupportedLegacyCheckpoints(): array
    {
        return ['inactive point' => ['INACTIVE', 'POINT'], 'polygon zone' => ['ACTIVE', 'ZONE']];
    }

    #[DataProvider('unsupportedLegacyCheckpoints')]
    public function test_legacy_checkpoint_normalization_preserves_dependency_validation_and_zone_geometry(string $status, string $type): void
    {
        [$admin, $scenario, $version] = $this->scenarioFixture(false);
        $attributes = ['organization_id' => $scenario->organization_id, 'status' => $status, 'type' => $type];
        if ($type === 'ZONE') {
            $attributes['geom'] = DB::raw("ST_GeomFromText('POLYGON((51 35,52 35,52 36,51 36,51 35))',4326)");
            $attributes['radius_meters'] = null;
        }
        $checkpoint = Checkpoint::factory()->create($attributes);
        $graph = $this->runtimeGraph('GPS / Location', ['checkpoint_id' => $checkpoint->public_id]);

        $saved = app(ScenarioService::class)->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->revision_token], $version->public_id);

        $this->assertSame($status === 'ACTIVE' ? 'PASS' : 'FAIL', $saved['validation']['status']);
        $config = collect($saved['graph']['nodes'])->firstWhere('node_key', 'task')['config'];
        $this->assertArrayNotHasKey('latitude', $config);
        if ($status !== 'ACTIVE') {
            $this->assertContains('DEPENDENCY_NOT_READY', array_column($saved['validation']['errors'], 'code'));
        }
    }
}

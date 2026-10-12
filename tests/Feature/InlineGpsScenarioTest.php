<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\QrTrigger;
use App\Services\Scenarios\GraphValidator;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\LocationFixtures;
use Tests\TestCase;

class InlineGpsScenarioTest extends TestCase
{
    use LocationFixtures, RefreshDatabase;

    public static function invalidCoordinates(): array
    {
        return ['latitude missing' => [['longitude' => 51.389, 'radius_m' => 50]], 'latitude invalid' => [['latitude' => 91, 'longitude' => 51.389, 'radius_m' => 50]],
            'longitude invalid' => [['latitude' => 35.6892, 'longitude' => 181, 'radius_m' => 50]], 'radius invalid' => [['latitude' => 35.6892, 'longitude' => 51.389, 'radius_m' => 0]]];
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
        [$admin, $scenario, $version] = $this->scenarioFixture(false);
        $trigger = QrTrigger::factory()->create(['organization_id' => $scenario->organization_id]);
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
    }
}

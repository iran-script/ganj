<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\ScenarioSubflow;
use App\Models\ScenarioSubflowVersion;
use App\Services\Scenarios\GraphValidator;
use App\Services\Scenarios\NodeTypeRegistry;
use App\Services\Scenarios\ScenarioGraph;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ScenarioFixtures;
use Tests\TestCase;

class ScenarioValidationTest extends TestCase
{
    use RefreshDatabase, ScenarioFixtures;

    public static function invalidGraphs(): array
    {
        return [
            'missing start' => ['missing start', 'MISSING_START'], 'multiple starts' => ['multiple starts', 'MULTIPLE_STARTS'],
            'missing finish' => ['missing finish', 'MISSING_FINISH'], 'unreachable finish' => ['unreachable finish', 'UNREACHABLE_FINISH'],
            'dangling' => ['dangling', 'DANGLING_EDGE'], 'duplicate nodes' => ['duplicate nodes', 'DUPLICATE_NODE_KEY'],
            'duplicate edges' => ['duplicate edges', 'DUPLICATE_EDGE_KEY'], 'unknown type' => ['unknown type', 'UNSUPPORTED_NODE_TYPE'],
            'invalid config' => ['invalid config', 'INVALID_NODE_CONFIG'], 'extra config' => ['extra config', 'INVALID_NODE_CONFIG'],
            'invalid condition' => ['invalid condition', 'INVALID_CONDITION'], 'variable reference' => ['variable reference', 'INVALID_VARIABLE_REFERENCE'],
            'condition reference' => ['condition reference', 'INVALID_CONDITION_REFERENCE'], 'variable default' => ['variable default', 'INVALID_VARIABLE_DEFAULT'],
            'duplicate variable' => ['duplicate variable', 'DUPLICATE_VARIABLE_KEY'], 'cycle' => ['cycle', 'LOOP_GUARD_UNSUPPORTED'],
            'subflow' => ['subflow', 'INVALID_SUBFLOW_DEPENDENCY'], 'media' => ['media', 'DEPENDENCY_NOT_READY'],
            'template expression' => ['template expression', 'INVALID_TEMPLATE_REFERENCE'], 'start incoming' => ['start incoming', 'START_HAS_INCOMING_EDGE'],
            'finish outgoing' => ['finish outgoing', 'FINISH_HAS_OUTGOING_EDGE'], 'branch' => ['branch', 'INVALID_BRANCH'],
        ];
    }

    #[DataProvider('invalidGraphs')]
    public function test_graph_validation_returns_stable_machine_readable_errors(string $case, string $code): void
    {
        [,$scenario] = $this->scenarioFixture(false);
        $graph = $this->simpleGraph();
        $group = 'a68917aa-82c3-4f84-a539-4f463b4b7f78';
        $condition = ['condition_group_id' => $group, 'left_operand' => 'score', 'operator' => '>', 'right_operand_json' => 100, 'value_type' => 'INTEGER', 'position' => 0];
        $variable = ['key' => 'attempts', 'type' => 'INTEGER', 'scope' => 'TEAM', 'default_value' => 0, 'writable' => true];
        switch ($case) {
            case 'missing start': $graph['nodes'][0]['node_type'] = 'Wait';
                $graph['nodes'][0]['config'] = ['duration_seconds' => 1];
                break;
            case 'multiple starts': $graph['nodes'][] = ['node_key' => 'second', 'node_type' => 'Start', 'config' => []];
                break;
            case 'missing finish': $graph['nodes'][1]['node_type'] = 'Wait';
                $graph['nodes'][1]['config'] = ['duration_seconds' => 1];
                break;
            case 'unreachable finish': $graph['edges'] = [];
                break;
            case 'dangling': $graph['edges'][0]['to_node'] = 'missing';
                break;
            case 'duplicate nodes': $graph['nodes'][] = $graph['nodes'][0];
                break;
            case 'duplicate edges': $graph['edges'][] = $graph['edges'][0];
                break;
            case 'unknown type': $graph['nodes'][0]['node_type'] = 'Execute PHP';
                break;
            case 'invalid config': $graph['nodes'][0]['node_type'] = 'Puzzle';
                break;
            case 'extra config': $graph['nodes'][0]['config'] = ['execute' => 'system()'];
                break;
            case 'invalid condition': $condition['right_operand_json'] = 'one hundred';
                $graph['conditions'] = [$condition];
                break;
            case 'variable reference': $condition['left_operand'] = 'variables.missing';
                $graph['conditions'] = [$condition];
                break;
            case 'condition reference': $graph['edges'][0]['condition_group_id'] = $group;
                break;
            case 'variable default': $variable['default_value'] = '0';
                $graph['variables'] = [$variable];
                break;
            case 'duplicate variable': $graph['variables'] = [$variable, $variable];
                break;
            case 'cycle': $graph['nodes'][] = ['node_key' => 'wait', 'node_type' => 'Wait', 'config' => ['duration_seconds' => 1]];
                $graph['edges'][] = ['edge_key' => 'back', 'from_node' => 'wait', 'to_node' => 'wait', 'priority' => 0];
                break;
            case 'subflow': $graph['nodes'][0]['node_type'] = 'Subflow';
                $graph['subflows'] = [['node_key' => 'start', 'subflow_id' => $group, 'version_no' => 1]];
                break;
            case 'media': $graph['nodes'][0]['node_type'] = 'Show Image';
                $graph['nodes'][0]['config'] = ['asset_id' => $group, 'checksum' => str_repeat('a', 64)];
                break;
            case 'template expression': $graph['nodes'][0]['node_type'] = 'Message';
                $graph['nodes'][0]['config'] = ['text' => '{{ system("id") }}'];
                break;
            case 'start incoming': $graph['edges'][] = ['edge_key' => 'back', 'from_node' => 'finish', 'to_node' => 'start', 'priority' => 1];
                break;
            case 'finish outgoing': $graph['edges'][] = ['edge_key' => 'back', 'from_node' => 'finish', 'to_node' => 'finish', 'priority' => 1];
                break;
            case 'branch': $graph['nodes'][0]['node_type'] = 'Branch';
                break;
        }
        $result = app(GraphValidator::class)->validate($graph, $scenario->organization_id);
        $this->assertSame('FAIL', $result['status']);
        $this->assertContains($code, array_column($result['errors'], 'code'));
        $this->assertDatabaseCount('scenario_validation_runs', 0);
    }

    public function test_typed_variables_conditions_and_templates_survive_graph_roundtrip(): void
    {
        [$admin,,$version] = $this->scenarioFixture();
        $graph = $this->simpleGraph();
        $group = 'a68917aa-82c3-4f84-a539-4f463b4b7f78';
        $graph['variables'] = [['key' => 'attempts', 'type' => 'INTEGER', 'scope' => 'TEAM', 'default_value' => 0, 'writable' => true]];
        $graph['conditions'] = [['condition_group_id' => $group, 'left_operand' => 'variables.attempts', 'operator' => '>=', 'right_operand_json' => 0, 'value_type' => 'INTEGER', 'position' => 0]];
        $graph['edges'][0]['condition_group_id'] = $group;
        $service = app(ScenarioService::class);
        $service->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->revision_token], $version->public_id);
        $result = $service->execute($admin, 'validate', [], $version->public_id);
        $this->assertSame('PASS', $result['status']);
        $read = app(ScenarioGraph::class)->read($version);
        $this->assertSame(0, $read['variables'][0]['default_value']);
        $this->assertSame($group, $read['edges'][0]['condition_group_id']);
        $this->assertSame('variables.attempts', $read['conditions'][0]['left_operand']);
        $this->publishFixture($admin, $version);
        $copy = $service->execute($admin, 'duplicate', [], $version->public_id);
        $this->assertSame('DRAFT', $copy['status']);
        $this->assertDatabaseCount('scenario_conditions', 3);
    }

    public function test_structurally_invalid_save_rolls_back_graph_revision_and_events(): void
    {
        [$admin,,$version] = $this->scenarioFixture();
        $graph = $this->simpleGraph();
        $graph['edges'][0]['to_node'] = 'missing';
        $before = app(ScenarioGraph::class)->read($version);
        $events = DB::table('outbox_events')->count();
        try {
            app(ScenarioService::class)->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->revision_token], $version->public_id);
            $this->fail('Invalid graph accepted.');
        } catch (ApiException $e) {
            $this->assertSame('VALIDATION_FAILED', $e->errorCode);
        }
        $this->assertSame($before, app(ScenarioGraph::class)->read($version));
        $this->assertSame($version->revision_token, $version->fresh()->revision_token);
        $this->assertSame($events, DB::table('outbox_events')->count());
    }

    public function test_published_subflow_is_pinned_and_cross_tenant_reference_fails(): void
    {
        [$admin,$scenario,$version] = $this->scenarioFixture();
        $subflow = ScenarioSubflow::factory()->create(['organization_id' => $scenario->organization_id]);
        $pinned = ScenarioSubflowVersion::factory()->create(['scenario_subflow_id' => $subflow->id, 'status' => 'PUBLISHED', 'published_at' => now()]);
        $graph = $this->simpleGraph();
        $graph['nodes'][] = ['node_key' => 'sub', 'node_type' => 'Subflow', 'config' => []];
        $graph['edges'][0]['to_node'] = 'sub';
        $graph['edges'][] = ['edge_key' => 'end', 'from_node' => 'sub', 'to_node' => 'finish', 'priority' => 0];
        $graph['subflows'] = [['node_key' => 'sub', 'subflow_id' => $subflow->public_id, 'version_no' => 1]];
        app(ScenarioService::class)->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->revision_token], $version->public_id);
        $this->publishFixture($admin, $version);
        ScenarioSubflowVersion::factory()->create(['scenario_subflow_id' => $subflow->id, 'version_no' => 2]);
        $this->assertDatabaseHas('scenario_subflow_bindings', ['scenario_version_id' => $version->id, 'subflow_version_id' => $pinned->id]);
        $other = ScenarioSubflow::factory()->create();
        $graph['subflows'][0]['subflow_id'] = $other->public_id;
        $this->assertContains('INVALID_SUBFLOW_DEPENDENCY', array_column(app(GraphValidator::class)->validate($graph, $scenario->organization_id)['errors'], 'code'));
    }

    public function test_dependency_change_after_validation_blocks_publish(): void
    {
        [$admin,$scenario,$version] = $this->scenarioFixture();
        $subflow = ScenarioSubflow::factory()->create(['organization_id' => $scenario->organization_id]);
        $pinned = ScenarioSubflowVersion::factory()->create(['scenario_subflow_id' => $subflow->id, 'status' => 'PUBLISHED', 'published_at' => now()]);
        $graph = $this->simpleGraph();
        $graph['nodes'][] = ['node_key' => 'sub', 'node_type' => 'Subflow', 'config' => []];
        $graph['edges'][0]['to_node'] = 'sub';
        $graph['edges'][] = ['edge_key' => 'end', 'from_node' => 'sub', 'to_node' => 'finish', 'priority' => 0];
        $graph['subflows'] = [['node_key' => 'sub', 'subflow_id' => $subflow->public_id, 'version_no' => 1]];
        $service = app(ScenarioService::class);
        $saved = $service->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->revision_token], $version->public_id);
        $service->execute($admin, 'validate', [], $version->public_id);
        $pinned->forceFill(['status' => 'ARCHIVED'])->save();
        $this->expectException(ApiException::class);
        $service->execute($admin, 'publish', ['revision_token' => $saved['revision_token']], $version->public_id);
    }

    public function test_archived_subflow_pin_can_be_copied_but_cannot_authorize_new_publication(): void
    {
        [$admin, $scenario, $version] = $this->scenarioFixture();
        $subflow = ScenarioSubflow::factory()->create(['organization_id' => $scenario->organization_id]);
        $pinned = ScenarioSubflowVersion::factory()->create(['scenario_subflow_id' => $subflow->id, 'status' => 'PUBLISHED', 'published_at' => now()]);
        $graph = $this->simpleGraph();
        $graph['nodes'][] = ['node_key' => 'sub', 'node_type' => 'Subflow', 'config' => []];
        $graph['edges'][0]['to_node'] = 'sub';
        $graph['edges'][] = ['edge_key' => 'end', 'from_node' => 'sub', 'to_node' => 'finish', 'priority' => 0];
        $graph['subflows'] = [['node_key' => 'sub', 'subflow_id' => $subflow->public_id, 'version_no' => 1]];
        $service = app(ScenarioService::class);
        $service->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->revision_token], $version->public_id);
        $this->publishFixture($admin, $version);
        $pinned->forceFill(['status' => 'ARCHIVED'])->save();
        $copy = $service->execute($admin, 'duplicate', [], $version->public_id);
        $this->assertSame('DRAFT', $copy['status']);
        $this->assertSame('FAIL', $service->execute($admin, 'validate', [], $copy['id'])['status']);
        $this->assertSame(1, app(ScenarioGraph::class)->read($version)['subflows'][0]['version_no']);
    }

    public function test_recursive_subflow_reference_is_rejected_without_executing_content(): void
    {
        [, $scenario] = $this->scenarioFixture(false);
        $subflow = ScenarioSubflow::factory()->create(['organization_id' => $scenario->organization_id]);
        $graph = $this->simpleGraph();
        $graph['nodes'][] = ['node_key' => 'sub', 'node_type' => 'Subflow', 'config' => []];
        $graph['edges'][0]['to_node'] = 'sub';
        $graph['edges'][] = ['edge_key' => 'end', 'from_node' => 'sub', 'to_node' => 'finish', 'priority' => 0];
        $graph['subflows'] = [['node_key' => 'sub', 'subflow_id' => $subflow->public_id, 'version_no' => 1]];
        ScenarioSubflowVersion::factory()->create(['scenario_subflow_id' => $subflow->id, 'status' => 'PUBLISHED', 'published_at' => now(), 'graph_json' => $graph]);
        $result = app(GraphValidator::class)->validate($graph, $scenario->organization_id);
        $this->assertSame('FAIL', $result['status']);
        $this->assertContains('INVALID_SUBFLOW_DEPENDENCY', array_column($result['errors'], 'code'));
    }

    public function test_random_player_declares_seed_contract_without_creating_runtime_state(): void
    {
        $registry = app(NodeTypeRegistry::class);
        $nodes = [['node_type' => 'Random Player', 'node_key' => 'random', 'config' => []]];
        $contract = $registry->randomizationContract($nodes);
        $this->assertSame($contract, $registry->randomizationContract($nodes));
        $this->assertTrue($contract['seed_required']);
        $this->assertSame('TeamScenarioInstance', $contract['seed_owner']);
        $this->assertSame('acknowledge', $registry->definitions()['Random Player']['runtime_handler']);
        $this->assertSame(0, DB::table('team_scenario_instances')->count());
    }

    public static function variableTypes(): array
    {
        return ['string' => ['STRING', 'value', true], 'string rejects number' => ['STRING', 1, false],
            'integer' => ['INTEGER', 1, true], 'integer rejects decimal' => ['INTEGER', 1.2, false],
            'decimal' => ['DECIMAL', 1.2, true], 'decimal rejects string' => ['DECIMAL', '1.2', false],
            'boolean' => ['BOOLEAN', false, true], 'boolean rejects integer' => ['BOOLEAN', 0, false],
            'datetime' => ['DATETIME', '2028-02-29T12:00:00Z', true], 'invalid calendar date' => ['DATETIME', '2027-02-29T12:00:00Z', false],
            'timezone required' => ['DATETIME', '2028-02-29T12:00:00', false],
            'json extension' => ['JSON', ['extension' => 1], true], 'json rejects untyped string' => ['JSON', 'opaque', false]];
    }

    #[DataProvider('variableTypes')]
    public function test_variable_defaults_are_strictly_typed(string $type, mixed $value, bool $valid): void
    {
        $this->assertSame($valid, app(GraphValidator::class)->matches($type, $value));
    }

    public function test_invalid_imported_subflow_shape_blocks_publish_with_controlled_error(): void
    {
        [$admin, $scenario, $version] = $this->scenarioFixture();
        $subflow = ScenarioSubflow::factory()->create(['organization_id' => $scenario->organization_id]);
        ScenarioSubflowVersion::factory()->create(['scenario_subflow_id' => $subflow->id, 'status' => 'PUBLISHED', 'published_at' => now(), 'graph_json' => ['subflows' => 'invalid']]);
        $graph = $this->simpleGraph();
        $graph['nodes'][] = ['node_key' => 'sub', 'node_type' => 'Subflow', 'config' => []];
        $graph['edges'][0]['to_node'] = 'sub';
        $graph['edges'][] = ['edge_key' => 'end', 'from_node' => 'sub', 'to_node' => 'finish', 'priority' => 0];
        $graph['subflows'] = [['node_key' => 'sub', 'subflow_id' => $subflow->public_id, 'version_no' => 1]];
        $service = app(ScenarioService::class);
        $saved = $service->execute($admin, 'saveGraph', $graph + ['revision_token' => $version->revision_token], $version->public_id);
        $this->assertSame('FAIL', $saved['validation']['status']);
        $this->assertSame('FAIL', $service->execute($admin, 'validate', [], $version->public_id)['status']);
        $this->assertDatabaseHas('scenario_validation_runs', ['scenario_version_id' => $version->id, 'status' => 'FAIL']);
    }
}

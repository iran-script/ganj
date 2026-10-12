<?php

namespace Tests\Feature;

use App\Models\RuntimeVariable;
use App\Models\ScoreEvent;
use App\Services\Runtime\RuntimeEngine;
use App\Services\Scenarios\GraphValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class AuthoringLogicTest extends TestCase
{
    use RefreshDatabase, RuntimeFixtures;

    public function test_variable_score_and_condition_execute_only_on_the_selected_branch(): void
    {
        $graph = $this->runtimeGraph('SetVariable', ['variable' => 'ready', 'value' => true]);
        $graph['variables'] = [['key' => 'ready', 'type' => 'BOOLEAN', 'scope' => 'TEAM', 'default_value' => false, 'writable' => true]];
        $graph['nodes'][] = ['node_key' => 'score', 'node_type' => 'Score', 'config' => ['operation' => 'ADD', 'amount' => 500, 'reason' => 'Route reward']];
        $graph['nodes'][] = ['node_key' => 'condition', 'node_type' => 'Condition', 'config' => ['operand' => 'score', 'operator' => '>=', 'value' => 500]];
        $graph['nodes'][] = ['node_key' => 'fallback', 'node_type' => 'Message', 'config' => ['text' => 'Fallback']];
        $graph['edges'][1]['to_node'] = 'score';
        foreach ([['score', 'condition', 'SUCCESS'], ['condition', 'finish', 'TRUE'], ['condition', 'fallback', 'FALSE'], ['fallback', 'finish', 'SUCCESS']] as $index => [$from, $to, $outcome]) {
            $graph['edges'][] = ['edge_key' => 'edge'.$index, 'from_node' => $from, 'to_node' => $to, 'priority' => $index, 'metadata' => ['outcome' => $outcome]];
        }

        [, , $instance, $player, $node] = $this->runtimeFixture($graph);

        $this->assertSame('COMPLETED', $node->status);
        $this->assertTrue(RuntimeVariable::where('key', 'ready')->sole()->value);
        $this->assertSame(500, ScoreEvent::sole()->delta);
        $nodes = $instance->nodes()->with('node')->get()->keyBy(fn ($item) => $item->node->node_key);
        $this->assertSame('LOCKED', $nodes['fallback']->status);
        $this->assertSame('AVAILABLE', $nodes['finish']->status);
        $this->runtimeAction($player, $nodes['finish'], 'start_node');
        $this->runtimeAction($player, $nodes['finish'], 'complete_node', ['acknowledged' => true]);
        $this->assertSame('COMPLETED', $instance->fresh()->status);
    }

    public static function comparisons(): array
    {
        return ['true' => [3, '>=', 2, 'TRUE'], 'false' => [1, '>=', 2, 'FALSE']];
    }

    #[DataProvider('comparisons')]
    public function test_typed_check_variable_routes_deterministically(int $initial, string $operator, int $expected, string $outcome): void
    {
        $graph = $this->runtimeGraph('CheckVariable', ['variable' => 'count', 'operator' => $operator, 'value' => $expected]);
        $graph['variables'] = [['key' => 'count', 'type' => 'INTEGER', 'scope' => 'TEAM', 'default_value' => $initial, 'writable' => true]];
        $graph['edges'][1]['metadata'] = ['outcome' => 'TRUE'];
        $graph['edges'][] = array_replace($graph['edges'][1], ['edge_key' => 'false', 'metadata' => ['outcome' => 'FALSE']]);

        [, , , , $node] = $this->runtimeFixture($graph);

        $this->assertSame($outcome, $node->state_json['outcome']);
        $this->assertSame('COMPLETED', $node->status);
    }

    public function test_server_delay_is_durable_and_expiry_is_idempotent(): void
    {
        $this->freezeTime();
        [, , , $player, $node] = $this->runtimeFixture($this->runtimeGraph('Time', ['mode' => 'DELAY', 'duration_seconds' => 30]));
        $this->assertSame('ACTIVE', $node->status);
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
        $this->travel(30)->seconds();

        $this->assertSame(1, app(RuntimeEngine::class)->expireTimers());
        $this->assertSame(0, app(RuntimeEngine::class)->expireTimers());
        $this->assertSame('COMPLETED', $node->fresh()->status);
    }

    public function test_timeout_selects_only_the_timeout_edge(): void
    {
        $this->freezeTime();
        $graph = $this->runtimeGraph('Time', ['mode' => 'TIMEOUT', 'duration_seconds' => 10]);
        $graph['nodes'][] = ['node_key' => 'late', 'node_type' => 'Message', 'config' => ['text' => 'Too late']];
        $graph['edges'][1]['metadata'] = ['outcome' => 'SUCCESS'];
        $graph['edges'][] = ['edge_key' => 'timeout', 'from_node' => 'task', 'to_node' => 'late', 'priority' => 1, 'metadata' => ['outcome' => 'TIMEOUT']];
        $graph['edges'][] = ['edge_key' => 'late-exit', 'from_node' => 'late', 'to_node' => 'finish', 'priority' => 0];
        [, , $instance, , $node] = $this->runtimeFixture($graph);
        $this->travel(10)->seconds();

        app(RuntimeEngine::class)->expireTimers();

        $this->assertSame('FAILED', $node->fresh()->status);
        $this->assertSame('TIMEOUT', $node->fresh()->state_json['outcome']);
        $this->assertSame('AVAILABLE', $instance->nodes()->whereHas('node', fn ($query) => $query->where('node_key', 'late'))->sole()->status);
    }

    public function test_random_player_selection_is_persisted_and_private(): void
    {
        $graph = $this->runtimeGraph('RandomPlayer', ['variable' => 'chosen', 'private_text' => 'Private clue']);
        $graph['variables'] = [['key' => 'chosen', 'type' => 'STRING', 'scope' => 'TEAM', 'default_value' => '', 'writable' => true]];
        [, $session, $instance, $player, $node] = $this->runtimeFixture($graph);
        $selected = RuntimeVariable::where('key', 'chosen')->sole()->value;

        app(RuntimeEngine::class)->read($player->user, $session->public_id, self: true, device: $player->device);

        $this->assertSame($selected, $node->fresh()->state_json['selected_player_id']);
        $this->assertTrue($instance->registration->players()->where('public_id', $selected)->exists());
    }

    public function test_validation_rejects_undefined_variable_and_ambiguous_outcomes(): void
    {
        [, $scenario] = $this->scenarioFixture(false);
        $graph = $this->runtimeGraph('CheckVariable', ['variable' => 'missing', 'operator' => '==', 'value' => true]);
        $graph['edges'][1]['metadata'] = ['outcome' => 'TRUE'];
        $graph['edges'][] = array_replace($graph['edges'][1], ['edge_key' => 'duplicate']);

        $result = app(GraphValidator::class)->validate($graph, $scenario->organization_id);

        $this->assertContains('INVALID_VARIABLE_REFERENCE', array_column($result['errors'], 'code'));
        $this->assertContains('AMBIGUOUS_EDGE_OUTCOME', array_column($result['errors'], 'code'));
        $this->assertContains('MISSING_EDGE_OUTCOME', array_column($result['errors'], 'code'));
    }
}

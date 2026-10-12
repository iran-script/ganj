<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Services\Scenarios\AuthoringNodeCatalog;
use App\Services\Scenarios\GraphValidator;
use App\Services\Scenarios\NodeTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class AuthoringValidationTest extends TestCase
{
    use RefreshDatabase, RuntimeFixtures;

    public static function invalidConfigs(): array
    {
        return [
            'negative delay' => ['Time', ['mode' => 'DELAY', 'duration_seconds' => -1]],
            'unbounded delay' => ['Time', ['mode' => 'DELAY', 'duration_seconds' => 9999999]],
            'wrong time window' => ['Time', ['mode' => 'TIME_WINDOW', 'starts_at' => '2026-10-13T12:00:00+00:00', 'ends_at' => '2026-10-13T11:00:00+00:00']],
            'script condition' => ['Condition', ['operand' => 'eval(code)', 'operator' => '==', 'value' => 1]],
            'malformed condition' => ['Condition', ['operand' => [], 'operator' => '==', 'value' => 1]],
            'unsafe variable' => ['SetVariable', ['variable' => '../unsafe', 'value' => true]],
            'unknown variable' => ['CheckVariable', ['variable' => 'missing', 'operator' => '==', 'value' => true]],
            'score set' => ['Score', ['operation' => 'SET', 'amount' => 100, 'reason' => 'Unsafe']],
            'unknown role' => ['RoleCheck', ['role' => 'CAPTAIN']],
            'too many roles' => ['AssignRole', ['roles' => ['NAVIGATOR', 'NAVIGATOR'], 'assignment_policy' => 'SEEDED_RANDOM']],
            'impossible team' => ['TeamTogether', ['minimum_player_count' => 5, 'support_policy' => 'DENY']],
            'malformed team' => ['TeamTogether', ['minimum_player_count' => 1, 'required_roles' => 5]],
            'unsafe MIME' => ['FileSubmission', ['instruction' => 'Upload', 'max_size_mb' => 10, 'allow_resubmit' => true, 'approval_mode' => 'AUTO_ACCEPT', 'allowed_mime_types' => ['text/html']]],
            'unbounded audio' => ['AudioSubmission', ['instruction' => 'Record', 'max_size_mb' => 10, 'allow_resubmit' => true, 'approval_mode' => 'AUTO_ACCEPT', 'max_duration_seconds' => 999999, 'record_policy' => 'RECORD_ONLY']],
        ];
    }

    #[DataProvider('invalidConfigs')]
    public function test_invalid_authoring_config_is_rejected_without_crashing(string $type, array $config): void
    {
        [, $scenario] = $this->scenarioFixture(false);
        $result = app(GraphValidator::class)->validate($this->runtimeGraph($type, $config), $scenario->organization_id);
        $this->assertSame('FAIL', $result['status']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_catalog_only_exposes_runtime_handlers_and_the_expected_node_families(): void
    {
        $catalog = app(AuthoringNodeCatalog::class)->describe();
        $this->assertCount(23, $catalog);
        foreach ($catalog as $definition) {
            $this->assertNotNull($definition['runtime_handler']);
            $this->assertArrayHasKey($definition['type'], app(NodeTypeRegistry::class)->definitions());
        }
        $this->assertNotContains('AR Challenge', array_column($catalog, 'type'));
        $rules = app(AuthoringNodeCatalog::class)->failureRules('PhotoSubmission');
        $this->assertArrayHasKey('timeout_seconds', $rules);
        $this->assertArrayNotHasKey('max_attempts', $rules);
        $this->assertArrayNotHasKey('retry_delay', $rules);
        $this->assertArrayHasKey('retry_delay', app(AuthoringNodeCatalog::class)->failureRules('QR'));
    }

    public function test_required_roles_can_exceed_the_minimum_participant_count(): void
    {
        [, $scenario] = $this->scenarioFixture(false);
        $graph = $this->runtimeGraph('TeamTogether', ['minimum_player_count' => 1, 'required_roles' => ['NAVIGATOR', 'DECODER']]);

        $result = app(GraphValidator::class)->validate($graph, $scenario->organization_id);

        $this->assertSame('PASS', $result['status'], json_encode($result['errors']));
    }

    public function test_time_window_uses_server_time_and_rejects_late_submissions_before_scheduler_runs(): void
    {
        $this->freezeTime();
        $graph = $this->runtimeGraph('Time', ['mode' => 'TIME_WINDOW', 'starts_at' => now()->addSeconds(10)->format(DATE_ATOM), 'ends_at' => now()->addSeconds(20)->format(DATE_ATOM)]);
        $graph['edges'][1]['metadata'] = ['outcome' => 'SUCCESS'];
        $graph['edges'][] = array_replace($graph['edges'][1], ['edge_key' => 'timeout', 'metadata' => ['outcome' => 'TIMEOUT']]);
        [, , , $player, $node] = $this->runtimeFixture($graph);
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node', ['acknowledged' => true])['complete']);
        $this->travel(20)->seconds();
        $this->expectException(ApiException::class);
        $this->runtimeAction($player, $node, 'complete_node', ['acknowledged' => true]);
    }

    public function test_elapsed_check_branches_from_runtime_time(): void
    {
        $graph = $this->runtimeGraph('Time', ['mode' => 'ELAPSED_CHECK', 'operator' => '<', 'value' => 30]);
        $graph['edges'][1]['metadata'] = ['outcome' => 'TRUE'];
        $graph['edges'][] = array_replace($graph['edges'][1], ['edge_key' => 'false', 'metadata' => ['outcome' => 'FALSE']]);
        [, , , , $node] = $this->runtimeFixture($graph);
        $this->assertSame('TRUE', $node->state_json['outcome']);
    }
}

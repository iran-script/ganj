<?php

namespace Tests\Feature;

use App\Models\HintConsumption;
use App\Models\HintDefinition;
use App\Models\ScoreEvent;
use App\Models\TeamScore;
use App\Services\Scoring\HintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class HintConsumptionTest extends TestCase
{
    use RefreshDatabase, RuntimeFixtures;

    public function test_hints_are_sequential_and_retry_has_one_penalty_and_no_hint_bonus(): void
    {
        $graph = $this->runtimeGraph();
        $graph['nodes'][1]['config']['hints'] = ['First clue', 'Second clue', 'Final clue'];
        $graph['nodes'][1]['config']['score'] = ['base' => 100, 'no_hint_bonus' => 50];
        [, , , $player, $node] = $this->runtimeFixture($graph);
        $this->runtimeAction($player, $node, 'start_node');
        HintDefinition::query()->insert(['scenario_node_id' => $node->scenario_node_id, 'hint_no' => 1, 'content' => 'First clue', 'penalty_score' => 5, 'created_at' => now()]);
        $service = app(HintService::class);

        $first = $service->consume($player->user, $player->device, $node->public_id, [], 'hint-first-key');
        $retry = $service->consume($player->user, $player->device, $node->public_id, [], 'hint-first-key');

        ksort($first);
        ksort($retry);
        $this->assertSame($first, $retry);
        $this->assertSame(1, $first['hint_no']);
        $this->assertSame('First clue', $first['content']);
        $this->assertDatabaseCount('hint_consumptions', 1);
        $this->assertSame(-5, TeamScore::sole()->score);
        $this->assertSame(1, TeamScore::sole()->hints_used);
        $this->assertSame(1, $node->fresh()->state_json['hints_used']);
        $this->assertSame(2, $service->consume($player->user, $player->device, $node->public_id, [], 'hint-second-key')['hint_no']);
        $this->assertSame(3, $service->consume($player->user, $player->device, $node->public_id, [], 'hint-third-key')['hint_no']);
        $this->assertDomainError('HINT_LIMIT_REACHED', fn () => $service->consume($player->user, $player->device, $node->public_id, [], 'hint-fourth-key'));
        $this->assertSame(3, HintConsumption::count());
        $this->assertSame(3, TeamScore::sole()->hints_used);
        $this->assertSame(3, ScoreEvent::where('source_type', 'HINT_PENALTY')->count());

        $this->runtimeAction($player, $node, 'submit_action', ['answer' => 'Secret']);

        $this->assertSame(0, ScoreEvent::where('source_type', 'NO_HINT_BONUS')->count());
        $this->assertSame(95, TeamScore::sole()->score);
        $replayed = $service->consume($player->user, $player->device, $node->public_id, [], 'hint-first-key');
        ksort($replayed);
        $this->assertSame($first, $replayed);
    }

    public function test_failed_consumption_rolls_back_reservation_and_allows_same_key_after_activation(): void
    {
        $graph = $this->runtimeGraph();
        $graph['nodes'][1]['config']['hints'] = ['First clue'];
        [, , , $player, $node] = $this->runtimeFixture($graph);
        $service = app(HintService::class);

        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => $service->consume($player->user, $player->device, $node->public_id, [], 'inactive-hint-key'));

        $this->assertDatabaseCount('hint_consumptions', 0);
        $this->assertFalse(DB::table('idempotency_keys')->where('key', 'hint:'.hash('sha256', 'inactive-hint-key'))->exists());
        $this->runtimeAction($player, $node, 'start_node');
        $this->assertSame(1, $service->consume($player->user, $player->device, $node->public_id, [], 'inactive-hint-key')['hint_no']);
        $this->assertDomainError('DEVICE_NOT_BOUND', fn () => $service->consume($player->user, null, $node->public_id, [], 'missing-device-key'));
        $this->assertDatabaseCount('hint_consumptions', 1);
    }
}

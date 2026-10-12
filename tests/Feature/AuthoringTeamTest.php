<?php

namespace Tests\Feature;

use App\Models\PlayerRoleAssignment;
use App\Services\GameMaster\LiveChallengeService;
use App\Services\Runtime\RuntimeProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class AuthoringTeamTest extends TestCase
{
    use RefreshDatabase, RuntimeFixtures;

    public static function barriers(): array
    {
        return [['TeamTogether'], ['Barrier'], ['SplitInformation']];
    }

    #[DataProvider('barriers')]
    public function test_team_nodes_require_distinct_participants(string $type): void
    {
        $config = ['minimum_player_count' => 2];
        if ($type === 'SplitInformation') {
            $config['role_content'] = ['NAVIGATOR' => ['text' => 'Secret north']];
        }
        [, , $instance, $player, $node] = $this->runtimeFixture($this->runtimeGraph($type, $config));
        $other = $instance->registration->players()->where('id', '<>', $player->id)->firstOrFail();
        $this->runtimeAction($player, $node, 'start_node');
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node', ['acknowledged' => true])['complete']);
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node', ['acknowledged' => true])['complete']);
        $this->assertTrue($this->runtimeAction($other, $node, 'complete_node', ['acknowledged' => true])['complete']);
        $this->assertSame('COMPLETED', $node->fresh()->status);
    }

    public function test_assign_role_and_split_information_preserve_role_privacy(): void
    {
        $graph = $this->runtimeGraph('AssignRole', ['roles' => ['NAVIGATOR', 'DECODER'], 'assignment_policy' => 'SEEDED_RANDOM']);
        $graph['nodes'][] = ['node_key' => 'private', 'node_type' => 'SplitInformation', 'config' => ['minimum_player_count' => 2, 'role_content' => ['NAVIGATOR' => ['text' => 'North clue'], 'DECODER' => ['text' => 'Secret code']]]];
        $graph['edges'][1]['to_node'] = 'private';
        $graph['edges'][] = ['edge_key' => 'private-exit', 'from_node' => 'private', 'to_node' => 'finish', 'priority' => 0];
        [, , $instance, , $node] = $this->runtimeFixture($graph);
        $this->assertSame('COMPLETED', $node->status);
        $this->assertSame(2, PlayerRoleAssignment::whereNull('released_at')->count());
        $private = $instance->nodes()->whereHas('node', fn ($query) => $query->where('node_key', 'private'))->sole();
        $navigatorId = PlayerRoleAssignment::where('role_code', 'NAVIGATOR')->whereNull('released_at')->sole()->game_session_player_id;
        $navigator = $instance->registration->players()->findOrFail($navigatorId);
        $others = $instance->registration->players()->where('id', '<>', $navigatorId)->get();
        $this->assertSame('North clue', app(RuntimeProjection::class)->node($private, player: $navigator)['config']['text']);
        foreach ($others as $other) {
            $projection = app(RuntimeProjection::class)->node($private, player: $other);
            $this->assertStringNotContainsString('North clue', json_encode($projection));
            $this->assertArrayNotHasKey('role_content', $projection['config']);
        }
    }

    public function test_relay_uses_existing_order_and_role_check_uses_the_acting_player(): void
    {
        $graph = $this->runtimeGraph('AssignRole', ['roles' => ['NAVIGATOR', 'DECODER'], 'assignment_policy' => 'LEAST_RECENTLY_USED']);
        $graph['nodes'][] = ['node_key' => 'relay', 'node_type' => 'Relay', 'config' => ['roles' => ['NAVIGATOR', 'DECODER']]];
        $graph['nodes'][] = ['node_key' => 'check', 'node_type' => 'RoleCheck', 'config' => ['role' => 'DECODER']];
        $graph['edges'][1]['to_node'] = 'relay';
        $graph['edges'][] = ['edge_key' => 'relay-exit', 'from_node' => 'relay', 'to_node' => 'check', 'priority' => 0];
        foreach (['TRUE', 'FALSE'] as $outcome) {
            $graph['edges'][] = ['edge_key' => 'check-'.$outcome, 'from_node' => 'check', 'to_node' => 'finish', 'priority' => 0, 'metadata' => ['outcome' => $outcome]];
        }
        [, , $instance] = $this->runtimeFixture($graph);
        $relay = $instance->nodes()->whereHas('node', fn ($query) => $query->where('node_key', 'relay'))->sole();
        $players = [];
        foreach (PlayerRoleAssignment::whereNull('released_at')->get() as $assignment) {
            $players[$assignment->role_code] = $instance->registration->players()->findOrFail($assignment->game_session_player_id);
        }
        $this->runtimeAction($players['NAVIGATOR'], $relay, 'start_node');
        $this->assertFalse($this->runtimeAction($players['NAVIGATOR'], $relay, 'complete_node', ['acknowledged' => true])['complete']);
        $this->assertTrue($this->runtimeAction($players['DECODER'], $relay, 'complete_node', ['acknowledged' => true])['complete']);
        $check = $instance->nodes()->whereHas('node', fn ($query) => $query->where('node_key', 'check'))->sole();
        $this->assertSame('TRUE', $check->state_json['outcome']);
    }

    public function test_live_challenge_node_requires_a_completed_gamemaster_challenge(): void
    {
        [$admin, $session, $instance, $player, $node] = $this->runtimeFixture($this->runtimeGraph('LiveChallenge', ['category' => 'TEAM_OBJECTIVE']));
        $this->runtimeAction($player, $node, 'start_node');
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node', ['acknowledged' => true])['complete']);
        $service = app(LiveChallengeService::class);
        $challenge = $service->create($admin, $session->public_id, ['type' => 'TEAM_OBJECTIVE', 'objective' => 'Complete the task', 'targets' => [['type' => 'TEAM', 'id' => $instance->registration->team->public_id]]], 'authoring-challenge');
        $id = $challenge['id'];
        $service->transition($player->user, $id, 'start', [], 'authoring-challenge-start');
        $service->transition($player->user, $id, 'submit', ['acknowledged' => true], 'authoring-challenge-submit');
        $this->assertTrue($this->runtimeAction($player, $node, 'complete_node', ['acknowledged' => true])['complete']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\GameSessionPlayer;
use App\Models\PlayerRoleAssignment;
use App\Models\RuntimeEvent;
use App\Models\RuntimeTimer;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Games\SessionService;
use App\Services\Outbox\OutboxService;
use App\Services\Runtime\RuntimeConditions;
use App\Services\Runtime\RuntimeEngine;
use App\Services\Runtime\RuntimeEvents;
use App\Services\Runtime\RuntimeTimers;
use App\Services\Runtime\RuntimeVariables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class RuntimeMechanicsTest extends TestCase
{
    use RefreshDatabase, RuntimeFixtures;

    public static function branches(): array
    {
        return ['true' => [true, 'yes', 'Branch'], 'fallback' => [false, 'no', 'Branch'], 'condition true' => [true, 'yes', 'Condition'], 'condition false' => [false, 'no', 'Condition']];
    }

    #[DataProvider('branches')]
    public function test_typed_conditions_select_deterministic_priority_or_fallback(bool $value, string $expected, string $type): void
    {
        $group = (string) Str::uuid();
        $graph = $this->runtimeGraph($type, $type === 'Condition' ? ['condition_group_id' => $group] : []);
        $graph['variables'] = [['key' => 'allowed', 'type' => 'BOOLEAN', 'scope' => 'TEAM', 'default_value' => $value, 'writable' => true]];
        $graph['conditions'] = [['condition_group_id' => $group, 'left_operand' => 'variables.allowed', 'operator' => '==', 'right_operand_json' => true, 'value_type' => 'BOOLEAN', 'position' => 0]];
        foreach (['yes', 'no', 'late'] as $key) {
            $graph['nodes'][] = ['node_key' => $key, 'node_type' => 'Message', 'config' => ['text' => $key]];
        }
        $graph['edges'] = [
            ['edge_key' => 'enter', 'from_node' => 'start', 'to_node' => 'task', 'priority' => 0],
            ['edge_key' => 'a', 'from_node' => 'task', 'to_node' => 'yes', 'priority' => 1, 'condition_group_id' => $group],
            ['edge_key' => 'b', 'from_node' => 'task', 'to_node' => 'late', 'priority' => 2, 'condition_group_id' => $group],
            ['edge_key' => 'fallback', 'from_node' => 'task', 'to_node' => 'no', 'priority' => 3],
        ];
        foreach (['yes', 'no', 'late'] as $key) {
            $graph['edges'][] = ['edge_key' => $key.'_end', 'from_node' => $key, 'to_node' => 'finish', 'priority' => 0];
        }
        [, , $instance] = $this->runtimeFixture($graph);
        $available = TeamNodeInstance::where('team_scenario_instance_id', $instance->id)->where('status', 'AVAILABLE')->with('node')->sole();
        $this->assertSame($expected, $available->node->node_key);
        $this->assertSame(2, TeamNodeInstance::where('team_scenario_instance_id', $instance->id)->where('status', 'COMPLETED')->count());
    }

    public function test_parallel_nodes_progress_independently_and_finish_waits_for_unresolved_branch(): void
    {
        $graph = $this->runtimeGraph('Message', ['text' => 'first']);
        $graph['nodes'][] = ['node_key' => 'split', 'node_type' => 'Split Team', 'config' => ['groups' => [2, 2]]];
        $graph['nodes'][] = ['node_key' => 'other', 'node_type' => 'Message', 'config' => ['text' => 'second']];
        $graph['edges'] = [
            ['edge_key' => 'begin', 'from_node' => 'start', 'to_node' => 'split', 'priority' => 0],
            ['edge_key' => 'left', 'from_node' => 'split', 'to_node' => 'task', 'priority' => 0],
            ['edge_key' => 'right', 'from_node' => 'split', 'to_node' => 'other', 'priority' => 1],
            ['edge_key' => 'left_end', 'from_node' => 'task', 'to_node' => 'finish', 'priority' => 0],
            ['edge_key' => 'right_end', 'from_node' => 'other', 'to_node' => 'finish', 'priority' => 0],
        ];
        [, , $instance, $player, $node] = $this->runtimeFixture($graph);
        $this->assertSame(2, TeamNodeInstance::where('status', 'AVAILABLE')->count());
        $other = TeamNodeInstance::whereHas('node', fn ($q) => $q->where('node_key', 'other'))->sole();
        $this->runtimeAction($player, $node, 'start_node');
        $this->runtimeAction($player, $other, 'start_node');
        $this->assertSame(2, TeamNodeInstance::where('status', 'ACTIVE')->count());
        $this->runtimeAction($player, $node, 'submit_action', ['acknowledged' => true]);
        $finish = TeamNodeInstance::whereHas('node', fn ($q) => $q->where('node_type', 'Finish'))->sole();
        $this->runtimeAction($player, $finish, 'start_node');
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => $this->runtimeAction($player, $finish, 'complete_node', ['acknowledged' => true]));
        $this->runtimeAction($player, $other, 'submit_action', ['acknowledged' => true]);
        $this->runtimeAction($player, $finish, 'complete_node', ['acknowledged' => true]);
        $this->assertSame('COMPLETED', $instance->fresh()->status);
    }

    public static function values(): array
    {
        return [['INTEGER', 1, 2, '2'], ['BOOLEAN', false, true, 1], ['STRING', 'a', 'b', 2],
            ['DECIMAL', 1.5, 2.5, '2.5'], ['DATETIME', '2026-01-01T00:00:00Z', '2026-02-01T00:00:00Z', 'bad'], ['JSON', [], ['a' => 1], 'bad']];
    }

    #[DataProvider('values')]
    public function test_variables_enforce_types_and_isolate_teams(string $type, mixed $initial, mixed $valid, mixed $invalid): void
    {
        $graph = $this->runtimeGraph();
        $graph['variables'] = [['key' => 'value', 'type' => $type, 'scope' => 'TEAM', 'default_value' => $initial, 'writable' => true]];
        [, , $instance] = $this->runtimeFixture($graph, teamCount: 2);
        $variables = app(RuntimeVariables::class);
        DB::transaction(fn () => $variables->write($instance, 'value', $valid));
        $this->assertSame($valid, $variables->read($instance, 'value', null, null));
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => DB::transaction(fn () => $variables->write($instance, 'value', $invalid)));
        $other = TeamScenarioInstance::whereKeyNot($instance->id)->sole();
        $this->assertSame($initial, $variables->read($other, 'value', null, null));
    }

    public function test_variable_scopes_and_readonly_templates_are_enforced_and_randomness_is_reproducible(): void
    {
        $graph = $this->runtimeGraph();
        foreach (['PLAYER', 'NODE', 'SCENARIO'] as $scope) {
            $graph['variables'][] = ['key' => strtolower($scope), 'type' => 'INTEGER', 'scope' => $scope, 'default_value' => 1, 'writable' => $scope !== 'SCENARIO'];
        }
        [, , $instance, $player, $node] = $this->runtimeFixture($graph);
        $variables = app(RuntimeVariables::class);
        $this->assertDatabaseCount('runtime_variables', 8);
        DB::transaction(fn () => $variables->write($instance, 'player', 2, $player));
        DB::transaction(fn () => $variables->write($instance, 'node', 3, node: $node));
        $this->assertSame(2, $variables->read($instance, 'player', $player, null));
        $this->assertSame(3, $variables->read($instance, 'node', null, $node));
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => DB::transaction(fn () => $variables->write($instance, 'player', 2)));
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => DB::transaction(fn () => $variables->write($instance, 'scenario', 2)));
        $random = app(RuntimeConditions::class);
        $this->assertSame($random->random($instance, 'selection'), app(RuntimeConditions::class)->random($instance->fresh(), 'selection'));
        $this->assertNotSame($random->random($instance, 'selection'), $random->random($instance, 'other'));
    }

    public function test_timer_survives_nested_team_session_pause_and_expires_once_after_resume(): void
    {
        $this->freezeTime();
        $graph = $this->runtimeGraph('Timer', ['duration_seconds' => 60, 'on_expire' => 'COMPLETE', 'pause_behavior' => 'PAUSE']);
        [$admin, $session, $instance, $player, $node] = $this->runtimeFixture($graph, teamCount: 2);
        $engine = app(RuntimeEngine::class);
        $this->runtimeAction($player, $node, 'start_node');
        $this->travel(20)->seconds();
        $engine->execute($admin, 'pause_team', $instance->public_id, ['reason' => 'Rest'], 'team-pause');
        $other = TeamScenarioInstance::whereKeyNot($instance->id)->sole();
        $this->assertSame('ACTIVE', $other->status);
        app(SessionService::class)->execute($admin, 'pause', ['reason' => 'Weather'], $session->public_id, key: 'session-weather-pause');
        $this->travel(100)->seconds();
        app(SessionService::class)->execute($admin, 'resume', ['reason' => 'Clear'], $session->public_id, key: 'session-weather-resume');
        $this->assertSame('PAUSED', $instance->fresh()->status);
        $this->assertSame(0, app(RuntimeEngine::class)->expireTimers());
        $engine->execute($admin, 'resume_team', $instance->public_id, ['reason' => 'Ready'], 'team-resume');
        $timer = RuntimeTimer::where('node_instance_id', $node->id)->sole();
        $this->assertSame(20, app(RuntimeTimers::class)->elapsed($timer));
        $this->assertSame(100, $timer->accumulated_paused_seconds);
        $this->travel(40)->seconds();
        $this->assertSame(1, app(RuntimeEngine::class)->expireTimers());
        $this->assertSame(0, app(RuntimeEngine::class)->expireTimers());
        $this->assertSame('COMPLETED', $node->fresh()->status);
        $this->assertSame('FINISHED', $timer->fresh()->status);
        $this->assertSame(1, RuntimeEvent::where('event_type', 'runtime.timer_finished.v1')->where('aggregate_id', $node->public_id)->count());
    }

    public function test_barrier_counts_distinct_contributors_and_finishes_only_when_required_count_is_met(): void
    {
        [, , $instance, $player, $node] = $this->runtimeFixture($this->runtimeGraph('Team Together', ['minimum_player_count' => 4]));
        $this->runtimeAction($player, $node, 'start_node');
        $first = $this->runtimeAction($player, $node, 'submit_action', ['acknowledged' => true], 'barrier-one');
        $retry = $this->runtimeAction($player, $node, 'submit_action', ['acknowledged' => true], 'barrier-one');
        $this->assertSame($first, $retry);
        $duplicate = $this->runtimeAction($player, $node, 'submit_action', ['acknowledged' => true]);
        $this->assertSame('DUPLICATE', $duplicate['result']);
        $others = $instance->registration->players()->whereKeyNot($player->id)->get();
        foreach ($others->take(2) as $other) {
            $this->runtimeAction($other, $node, 'submit_action', ['acknowledged' => true]);
        }
        $this->assertSame('ACTIVE', $node->fresh()->status);
        $this->runtimeAction($others->last(), $node, 'submit_action', ['acknowledged' => true]);
        $this->assertSame('COMPLETED', $node->fresh()->status);
        $this->assertSame(4, RuntimeEvent::where('aggregate_id', $node->public_id)->where('payload->data->contribution', true)->count());
    }

    public function test_role_rotation_keeps_four_slots_support_restrictions_and_history(): void
    {
        $graph = $this->runtimeGraph();
        $roles = ['SCANNER', 'NAVIGATOR', 'DECODER', 'AR_OPERATOR'];
        $graph['nodes'][] = ['node_key' => 'roles', 'node_type' => 'Assign Roles', 'config' => ['roles' => $roles]];
        $graph['nodes'][] = ['node_key' => 'rotate', 'node_type' => 'Swap Roles', 'config' => ['roles' => $roles]];
        $graph['edges'] = [
            ['edge_key' => 'a', 'from_node' => 'start', 'to_node' => 'roles', 'priority' => 0],
            ['edge_key' => 'b', 'from_node' => 'roles', 'to_node' => 'task', 'priority' => 0],
            ['edge_key' => 'c', 'from_node' => 'task', 'to_node' => 'rotate', 'priority' => 0],
            ['edge_key' => 'd', 'from_node' => 'rotate', 'to_node' => 'finish', 'priority' => 0],
        ];
        [$admin, , $instance, , $node] = $this->runtimeFixture($graph, playerCount: 8);
        $assigned = PlayerRoleAssignment::whereNull('released_at')->get();
        $decoder = $instance->registration->players()->findOrFail($assigned->firstWhere('role_code', 'DECODER')->game_session_player_id);
        $support = $instance->registration->players()->whereNotIn('id', $assigned->pluck('game_session_player_id'))->firstOrFail();
        $this->assertSame(4, $assigned->count());
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => $this->runtimeAction($support, $node, 'start_node'));
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => app(RuntimeEngine::class)->execute($admin, 'assign_role', $node->public_id,
            ['reason' => 'Fifth slot', 'player_id' => $support->public_id, 'role_code' => 'DECODER', 'slot_no' => 1], 'fifth-role'));
        $this->runtimeAction($decoder, $node, 'start_node');
        $this->runtimeAction($decoder, $node, 'submit_action', ['answer' => 'Secret']);
        $this->assertSame(4, PlayerRoleAssignment::whereNotNull('released_at')->count());
        $new = PlayerRoleAssignment::whereNull('released_at')->pluck('game_session_player_id')->all();
        $this->assertCount(4, $new);
        $this->assertSame([], array_intersect($assigned->pluck('game_session_player_id')->all(), $new));
    }

    public function test_disconnect_and_reconnect_preserve_progress_and_restore_from_postgresql(): void
    {
        [$admin, $session, $instance, $player, $node] = $this->runtimeFixture();
        $this->runtimeAction($player, $node, 'start_node');
        $this->runtimeAction($player, $node, 'submit_action', ['answer' => 'bad']);
        app(RuntimeEngine::class)->execute($player->user, 'disconnect_player', $instance->public_id, [], 'disconnect-key', $player->device);
        app(SessionService::class)->execute($admin, 'pause', [], $session->public_id, key: 'reconnect-session-pause');
        $result = app(RuntimeEngine::class)->execute($player->user, 'reconnect_player', $instance->public_id, [], 'reconnect-key', $player->device);
        $this->assertSame('ACTIVE', $player->fresh()->participation_status);
        $this->assertSame('PAUSED', $result['runtime']['status']);
        $this->assertSame('ACTIVE', $node->fresh()->status);
        $this->assertSame(1, $node->fresh()->attempt_count);
        $this->assertDatabaseHas('outbox_events', ['topic' => 'player_session.reconnected.v1']);
    }

    public static function answers(): array
    {
        return [
            ['Password', true, true, ' Secret ', true],
            ['Password', true, false, ' Secret ', false],
            ['Text Answer', false, true, 'secret', true],
            ['Puzzle', true, true, 'secret', false],
        ];
    }

    #[DataProvider('answers')]
    public function test_answer_normalization_follows_only_frozen_configuration(string $type, bool $case, bool $trim, string $answer, bool $accepted): void
    {
        [, , , $player, $node] = $this->runtimeFixture($this->runtimeGraph($type, ['prompt' => 'Code', 'expected_answer' => 'Secret', 'case_sensitive' => $case, 'trim_whitespace' => $trim]));
        $this->runtimeAction($player, $node, 'start_node');
        $result = $this->runtimeAction($player, $node, 'submit_action', ['answer' => $answer]);
        $this->assertSame($accepted, $result['accepted']);
        $this->assertSame($accepted ? 'COMPLETED' : 'ACTIVE', $node->fresh()->status);
    }

    public function test_multiple_choice_checks_server_option_references_and_projection_hides_answer(): void
    {
        [, $session, , $player, $node] = $this->runtimeFixture($this->runtimeGraph('Multiple Choice', ['prompt' => 'Choose', 'options' => ['a' => 'Alpha', 'b' => 'Beta'], 'correct_option_refs' => ['b'], 'multi_select' => false]));
        $read = app(RuntimeEngine::class)->read($player->user, $session->public_id, self: true, device: $player->device);
        $this->assertArrayNotHasKey('correct_option_refs', $read['nodes'][1]['config']);
        $this->runtimeAction($player, $node, 'start_node');
        $this->assertFalse($this->runtimeAction($player, $node, 'submit_action', ['selected_option_refs' => ['a']])['accepted']);
        $this->assertTrue($this->runtimeAction($player, $node, 'submit_action', ['selected_option_refs' => ['b']])['accepted']);
    }

    public function test_role_release_reassignment_and_captain_have_no_implicit_decoder_capability(): void
    {
        [$admin, , $instance, $captain, $node] = $this->runtimeFixture();
        $other = $instance->registration->players()->whereKeyNot($captain->id)->firstOrFail();
        $engine = app(RuntimeEngine::class);
        $engine->execute($admin, 'assign_role', $node->public_id, ['reason' => 'Decoder', 'player_id' => $other->public_id, 'role_code' => 'DECODER', 'slot_no' => 1], 'assign-decoder');
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => $this->runtimeAction($captain, $node, 'start_node'));
        $assignment = PlayerRoleAssignment::sole();
        $engine->execute($admin, 'release_role', $node->public_id, ['reason' => 'Rotation', 'assignment_id' => $assignment->public_id], 'release-decoder');
        $engine->execute($admin, 'assign_role', $node->public_id, ['reason' => 'New decoder', 'player_id' => $captain->public_id, 'role_code' => 'DECODER', 'slot_no' => 1], 'assign-new-decoder');
        $this->runtimeAction($captain, $node, 'start_node');
        $this->assertNotNull($assignment->fresh()->released_at);
        $this->assertDatabaseCount('player_role_assignments', 2);
        $this->assertSame('ACTIVE', $node->fresh()->status);
    }

    public function test_timer_failure_and_failed_worker_retry_are_durable(): void
    {
        $this->freezeTime();
        [, , , $player, $node] = $this->runtimeFixture($this->runtimeGraph('Timer', ['duration_seconds' => 10, 'on_expire' => 'FAIL', 'pause_behavior' => 'PAUSE']));
        $this->runtimeAction($player, $node, 'start_node');
        $this->travel(10)->seconds();
        $events = app(RuntimeEvents::class);
        $this->app->instance(RuntimeEvents::class, new class(app(OutboxService::class), app(AuditService::class)) extends RuntimeEvents
        {
            public function record(string $action, TeamScenarioInstance $instance, ?TeamNodeInstance $node = null, ?User $actor = null, ?GameSessionPlayer $player = null, array $data = [], ?string $reason = null): void
            {
                throw new \RuntimeException('Worker persistence interruption');
            }
        });
        $this->assertSame(0, app(RuntimeEngine::class)->expireTimers());
        $this->assertSame('ACTIVE', $node->fresh()->status);
        $this->assertDatabaseHas('runtime_commands', ['command_type' => 'timer_expiry', 'status' => 'FAILED']);
        $this->app->instance(RuntimeEvents::class, $events);
        $this->assertSame(0, app(RuntimeEngine::class)->expireTimers());
        $this->travel(60)->seconds();
        $this->assertSame(1, app(RuntimeEngine::class)->expireTimers());
        $this->assertSame('FAILED', $node->fresh()->status);
        $this->assertDatabaseHas('runtime_events', ['event_type' => 'runtime.node_failed.v1', 'aggregate_id' => $node->public_id]);
    }

    public function test_explicit_wall_clock_timer_keeps_time_but_cannot_progress_during_pause(): void
    {
        $this->freezeTime();
        [$admin, $session, , $player, $node] = $this->runtimeFixture($this->runtimeGraph('Timer', ['duration_seconds' => 10, 'on_expire' => 'COMPLETE', 'pause_behavior' => 'CONTINUE']));
        $this->runtimeAction($player, $node, 'start_node');
        app(SessionService::class)->execute($admin, 'pause', [], $session->public_id, key: 'wall-clock-pause');
        $this->travel(20)->seconds();
        $timer = RuntimeTimer::where('node_instance_id', $node->id)->sole();
        $this->assertSame(20, app(RuntimeTimers::class)->elapsed($timer));
        $this->assertSame(0, app(RuntimeEngine::class)->expireTimers());
        app(SessionService::class)->execute($admin, 'resume', [], $session->public_id, key: 'wall-clock-resume');
        $this->assertSame(1, app(RuntimeEngine::class)->expireTimers());
        $this->assertSame('COMPLETED', $node->fresh()->status);
    }

    public function test_random_player_selection_is_persisted_and_enforced_after_reload(): void
    {
        [, $session, $instance, , $node] = $this->runtimeFixture($this->runtimeGraph('Random Player', []));
        $selected = $node->fresh()->state_json['selected_player_id'];
        $player = $instance->registration->players()->where('public_id', $selected)->sole();
        $other = $instance->registration->players()->whereKeyNot($player->id)->firstOrFail();
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => $this->runtimeAction($other, $node, 'start_node'));
        $read = app(RuntimeEngine::class)->read($player->user, $session->public_id, self: true, device: $player->device);
        $this->assertSame($selected, collect($read['nodes'])->firstWhere('id', $node->public_id)['selected_player_id']);
        $this->runtimeAction($player, $node->fresh(), 'start_node');
        $this->runtimeAction($player, $node->fresh(), 'complete_node', ['acknowledged' => true]);
        $this->assertSame('COMPLETED', $node->fresh()->status);
    }
}

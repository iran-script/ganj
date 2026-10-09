<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\RuntimeEvent;
use App\Models\TeamNodeInstance;
use App\Services\Games\SessionService;
use App\Services\Runtime\RuntimeEngine;
use App\Services\Runtime\RuntimeInitializer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class RuntimeConcurrencyTest extends TestCase
{
    use DatabaseMigrations, RuntimeFixtures;

    public static function races(): array
    {
        return [['completion'], ['barrier'], ['pause submit'], ['duplicate'], ['conflicting key'], ['role'], ['bootstrap'], ['finish']];
    }

    #[DataProvider('races')]
    public function test_postgresql_contenders_wait_and_recheck_without_duplicate_transition(string $race): void
    {
        $graph = $race === 'barrier' ? $this->runtimeGraph('Team Together', ['minimum_player_count' => 2]) : null;
        [$admin, $session, $instance, $player, $node, $version] = $this->runtimeFixture($graph);
        $other = $instance->registration->players()->whereKeyNot($player->id)->firstOrFail();
        $engine = app(RuntimeEngine::class);
        $this->runtimeAction($player, $node, 'start_node');
        $payload = $race === 'barrier' ? ['acknowledged' => true] : ['answer' => 'Secret'];
        if ($race === 'finish') {
            $this->runtimeAction($player, $node, 'complete_node', $payload);
            $node = TeamNodeInstance::whereHas('node', fn ($q) => $q->where('node_type', 'Finish'))->sole();
            $this->runtimeAction($player, $node, 'start_node');
            $payload = ['acknowledged' => true];
        }
        $one = fn () => $engine->execute($player->user, 'complete_node', $node->public_id, $payload, 'race-command-one', $player->device);
        $two = fn () => app(RuntimeEngine::class)->execute($other->user, 'complete_node', $node->public_id, $payload, 'race-command-two', $other->device);
        $error = in_array($race, ['completion', 'finish'], true) ? 'STATE_TRANSITION_INVALID' : null;
        if ($race === 'pause submit') {
            $one = fn () => app(SessionService::class)->execute($admin, 'pause', ['reason' => 'Race pause'], $session->public_id, key: 'race-session-pause');
            $error = 'STATE_TRANSITION_INVALID';
        } elseif (in_array($race, ['duplicate', 'conflicting key'], true)) {
            $two = fn () => app(RuntimeEngine::class)->execute($player->user, 'complete_node', $node->public_id,
                $race === 'duplicate' ? $payload : ['answer' => 'changed'], 'race-command-one', $player->device);
            $error = $race === 'duplicate' ? null : 'IDEMPOTENCY_CONFLICT';
        } elseif ($race === 'role') {
            $one = fn () => $engine->execute($admin, 'assign_role', $node->public_id, ['reason' => 'Role one', 'player_id' => $player->public_id, 'role_code' => 'DECODER', 'slot_no' => 1], 'race-role-one');
            $two = fn () => app(RuntimeEngine::class)->execute($admin, 'assign_role', $node->public_id, ['reason' => 'Role two', 'player_id' => $other->public_id, 'role_code' => 'DECODER', 'slot_no' => 1], 'race-role-two');
            $error = 'ACTION_NOT_ALLOWED';
        } elseif ($race === 'bootstrap') {
            $rosters = [['team_id' => $instance->registration->team->public_id, 'scenario_version_ref' => $version->public_id]];
            $one = fn () => DB::transaction(fn () => app(RuntimeInitializer::class)->bootstrap($session, $rosters, 'race-bootstrap'));
            $two = $one;
        }
        $default = DB::getDefaultConnection();
        config(['database.connections.runtime_probe' => config('database.connections.'.$default)]);
        $primary = DB::connection($default);
        DB::connection('runtime_probe')->statement("SET lock_timeout = '200ms'");
        $primary->beginTransaction();
        try {
            $one();
            DB::setDefaultConnection('runtime_probe');
            try {
                $two();
                $this->fail('The concurrent command bypassed the PostgreSQL lock.');
            } catch (QueryException $e) {
                $this->assertSame('55P03', $e->errorInfo[0]);
            }
            DB::setDefaultConnection($default);
            $primary->commit();
            DB::setDefaultConnection('runtime_probe');
            try {
                $two();
                $this->assertNull($error);
            } catch (ApiException $e) {
                $this->assertSame($error, $e->errorCode);
            }
        } finally {
            DB::setDefaultConnection($default);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('runtime_probe');
        }
        if (in_array($race, ['completion', 'barrier', 'duplicate', 'conflicting key', 'finish'], true)) {
            $this->assertSame(1, RuntimeEvent::where('aggregate_id', $node->public_id)->where('event_type', 'runtime.node_completed.v1')->count());
        }
        if ($race === 'role') {
            $this->assertDatabaseCount('player_role_assignments', 1);
        }
        $this->assertDatabaseCount('team_scenario_instances', 1);
    }
}

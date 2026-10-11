<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\HintConsumption;
use App\Models\ScoreEvent;
use App\Models\TeamScore;
use App\Services\Scoring\HintService;
use App\Services\Scoring\ScoringService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class ScoringConcurrencyTest extends TestCase
{
    use DatabaseMigrations, RuntimeFixtures;

    public static function races(): array
    {
        return [['hint'], ['hint retry'], ['deltas'], ['duplicate completion'], ['finalize'], ['finalize vs delta'], ['manual retry'], ['rebuild']];
    }

    #[DataProvider('races')]
    public function test_postgresql_serializes_scoring_mutations_and_persists_one_result(string $race): void
    {
        $graph = $this->runtimeGraph();
        $graph['nodes'][1]['config']['hints'] = ['First', 'Second', 'Third'];
        [, , $instance, $player, $node] = $this->runtimeFixture($graph);
        $this->runtimeAction($player, $node, 'start_node');
        $score = fn (string $key, string $source = 'NODE_COMPLETION') => app(ScoringService::class)->apply($instance, $player, $node, 10, $source, 'Verified reward', $key);
        $one = fn () => $score('scoring-race-one');
        $two = fn () => $score('scoring-race-two');
        $expected = 20;
        $error = null;
        if (str_starts_with($race, 'hint')) {
            $one = fn () => app(HintService::class)->consume($player->user, $player->device, $node->public_id, [], 'hint-race-one');
            $two = fn () => app(HintService::class)->consume($player->user, $player->device, $node->public_id, [], $race === 'hint retry' ? 'hint-race-one' : 'hint-race-two');
            $expected = 0;
        } elseif ($race === 'duplicate completion') {
            $two = $one;
            $expected = 10;
        } elseif ($race === 'manual retry') {
            $one = fn () => $score('manual-race-one', 'MANUAL_ADJUSTMENT');
            $two = $one;
            $expected = 10;
        } elseif (str_starts_with($race, 'finalize')) {
            $score('before-finalization');
            $one = fn () => app(ScoringService::class)->finalize($instance);
            if ($race === 'finalize') {
                $two = $one;
            } else {
                $error = 'STATE_TRANSITION_INVALID';
            }
            $expected = 10;
        } elseif ($race === 'rebuild') {
            $two = fn () => app(ScoringService::class)->rebuild($instance->game_session_id, $instance->game_session_team_id);
            $expected = 10;
        }

        $default = DB::getDefaultConnection();
        config(['database.connections.score_probe' => config('database.connections.'.$default)]);
        DB::connection('score_probe')->statement("SET lock_timeout = '200ms'");
        $primary = DB::connection($default);
        $primary->beginTransaction();
        try {
            $first = $one();
            DB::setDefaultConnection('score_probe');
            try {
                $two();
                $this->fail('The competing mutation bypassed PostgreSQL serialization.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            }
            DB::setDefaultConnection($default);
            $primary->commit();
            DB::setDefaultConnection('score_probe');
            try {
                $second = $two();
                $this->assertNull($error);
                if ($race === 'hint retry') {
                    ksort($first);
                    ksort($second);
                    $this->assertSame($first, $second);
                }
            } catch (ApiException $exception) {
                $this->assertSame($error, $exception->errorCode);
            }
        } finally {
            DB::setDefaultConnection($default);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('score_probe');
        }

        $this->assertSame($expected, TeamScore::sole()->score);
        $this->assertSame($expected, (int) ScoreEvent::sum('delta'));
        $this->assertSame($expected, app(ScoringService::class)->rebuild($instance->game_session_id, $instance->game_session_team_id)->score);
        if (str_starts_with($race, 'hint')) {
            $count = $race === 'hint retry' ? 1 : 2;
            $this->assertSame($count, HintConsumption::count());
            $this->assertSame($count, TeamScore::sole()->hints_used);
            $this->assertSame($count, $node->fresh()->state_json['hints_used']);
            $this->assertSame($count, ScoreEvent::where('source_type', 'HINT_PENALTY')->count());
        } else {
            $this->assertSame($race === 'deltas' ? 2 : 1, ScoreEvent::count());
        }
        if (str_starts_with($race, 'finalize')) {
            $this->assertTrue(TeamScore::sole()->finalized);
            $this->assertSame(1, DB::table('outbox_events')->where('topic', 'score.finalized.v1')->count());
        }
    }
}

<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\GameSession;
use App\Models\LiveChallenge;
use App\Models\PlayerRoleAssignment;
use App\Models\QrTrigger;
use App\Models\ScoreEvent;
use App\Models\TriggerToken;
use App\Models\UserDevice;
use App\Services\GameMaster\LiveChallengeService;
use App\Services\Games\SessionService;
use App\Services\Qr\QrService;
use App\Services\Runtime\RuntimeEngine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class GameplayContentionTest extends TestCase
{
    use DatabaseMigrations, RuntimeFixtures;

    private function contend(callable $one, callable $two, ?string $error = null): void
    {
        $default = DB::getDefaultConnection();
        config(['database.connections.gameplay_probe' => config('database.connections.'.$default)]);
        DB::connection('gameplay_probe')->statement("SET lock_timeout = '200ms'");
        $primary = DB::connection($default);
        $primary->beginTransaction();
        try {
            $one();
            DB::setDefaultConnection('gameplay_probe');
            try {
                $two();
                $this->fail('The concurrent gameplay operation bypassed the canonical lock.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            }
            DB::setDefaultConnection($default);
            $primary->commit();
            DB::setDefaultConnection('gameplay_probe');
            try {
                $two();
                $this->assertNull($error);
            } catch (ApiException $exception) {
                $this->assertSame($error, $exception->errorCode);
            }
        } finally {
            DB::setDefaultConnection($default);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('gameplay_probe');
        }
    }

    public static function qrRaces(): array
    {
        return [['consume'], ['duplicate scan'], ['completion'], ['revoke']];
    }

    #[DataProvider('qrRaces')]
    public function test_qr_contention_preserves_one_token_effect(string $race): void
    {
        $id = (string) Str::uuid();
        $secret = bin2hex(random_bytes(32));
        [$admin, $session, , $player, $node] = $this->runtimeFixture($this->runtimeGraph('QR', ['trigger_id' => $id]),
            beforePublish: fn (GameSession $session) => QrTrigger::factory()->create(['public_id' => $id, 'organization_id' => $session->organization_id, 'identifier_hash' => hash('sha256', $secret)]));
        $this->runtimeAction($player, $node, 'start_node');
        $scan = fn (string $key) => app(QrService::class)->scan($player->user, $player->device,
            ['session_id' => $session->public_id, 'qr_identifier' => $secret], $key);
        if ($race === 'duplicate scan') {
            $this->contend(fn () => $scan('duplicate-scan-key'), fn () => $scan('duplicate-scan-key'));
            $this->assertDatabaseCount('trigger_tokens', 1);
            $this->assertDatabaseCount('qr_scan_events', 1);
        } elseif ($race === 'revoke') {
            $scan('before-revoke-key');
            $this->contend(fn () => app(QrService::class)->lifecycle($admin, $id, 'REVOKED'), fn () => $scan('after-revoke-key'), 'QR_INVALID');
            $this->assertNotNull(TriggerToken::sole()->revoked_at);
            $this->assertDatabaseCount('trigger_tokens', 1);
        } else {
            $token = $scan('before-consume-key')['one_time_token'];
            $one = fn () => $this->runtimeAction($player, $node, 'submit_action', ['qr_token' => $token], 'consume-race-one');
            $two = $race === 'consume' ? fn () => $this->runtimeAction($player, $node, 'submit_action', ['qr_token' => $token], 'consume-race-two') : fn () => $scan('scan-after-completion');
            $this->contend($one, $two, $race === 'consume' ? 'STATE_TRANSITION_INVALID' : 'NODE_NOT_ACTIVE');
            $this->assertSame('COMPLETED', $node->fresh()->status);
            $this->assertNotNull(TriggerToken::sole()->consumed_at);
            $this->assertSame(1, DB::table('outbox_events')->where('topic', 'qr.token_consumed.v1')->count());
        }
    }

    public static function challengeRaces(): array
    {
        return [['duplicate'], ['cancel'], ['expiry']];
    }

    #[DataProvider('challengeRaces')]
    public function test_challenge_contention_has_one_terminal_result_and_reward(string $race): void
    {
        $this->freezeTime();
        [$admin, $session, , $player] = $this->runtimeFixture();
        $service = app(LiveChallengeService::class);
        $challenge = $service->create($admin, $session->public_id, ['type' => 'TEAM_OBJECTIVE', 'objective' => 'Objective', 'duration_seconds' => 10,
            'targets' => [['type' => 'PLAYER', 'id' => $player->public_id]], 'reward' => ['score_delta' => 25]], 'race-challenge-create');
        $id = $challenge['id'];
        $service->transition($player->user, $id, 'ack', [], 'race-challenge-ack');
        $submit = fn () => app(LiveChallengeService::class)->transition($player->user, $id, 'submit', ['acknowledged' => true], 'race-challenge-submit');
        if ($race === 'duplicate') {
            $this->contend($submit, $submit);
        } elseif ($race === 'cancel') {
            $this->contend(fn () => $service->transition($admin, $id, 'cancel', ['reason' => 'Field safety'], 'race-challenge-cancel'), $submit, 'STATE_TRANSITION_INVALID');
        } else {
            $this->travel(10)->seconds();
            $this->contend(fn () => $this->assertDomainError('STATE_TRANSITION_INVALID', fn () => $service->transition($player->user, $id, 'start', [], 'race-challenge-expire')), $submit, 'STATE_TRANSITION_INVALID');
        }
        $this->assertSame(match ($race) {
            'duplicate' => 'COMPLETED', 'cancel' => 'CANCELLED', default => 'EXPIRED'
        }, LiveChallenge::sole()->status);
        $this->assertDatabaseCount('live_challenge_submissions', $race === 'duplicate' ? 1 : 0);
        $this->assertSame($race === 'duplicate' ? 25 : 0, (int) ScoreEvent::sum('delta'));
        $this->assertSame($race === 'duplicate' ? 1 : 0, ScoreEvent::count());
    }

    public function test_final_barrier_contribution_is_recorded_once(): void
    {
        [, , $instance, $player, $node] = $this->runtimeFixture($this->runtimeGraph('Team Together', ['minimum_player_count' => 2]));
        $other = $instance->registration->players()->whereKeyNot($player->id)->firstOrFail();
        $this->runtimeAction($player, $node, 'start_node');
        $this->runtimeAction($player, $node, 'submit_action', ['acknowledged' => true]);
        $this->contend(fn () => $this->runtimeAction($other, $node, 'submit_action', ['acknowledged' => true], 'barrier-final-one'),
            fn () => $this->runtimeAction($other, $node, 'submit_action', ['acknowledged' => true], 'barrier-final-two'), 'STATE_TRANSITION_INVALID');
        $this->assertSame('COMPLETED', $node->fresh()->status);
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'runtime.node_completed.v1')->where('aggregate_id', $node->public_id)->count());
    }

    public function test_simultaneous_fifth_primary_assignment_preserves_four_slots(): void
    {
        [$admin, , $instance, , $node] = $this->runtimeFixture($this->runtimeGraph('Message', ['text' => 'Roles']), playerCount: 5);
        $players = $instance->registration->players()->orderBy('id')->get();
        $assign = fn (int $index, string $role, int $slot, string $key) => app(RuntimeEngine::class)->execute($admin, 'assign_role', $node->public_id,
            ['reason' => 'Assignment', 'player_id' => $players[$index]->public_id, 'role_code' => $role, 'slot_no' => $slot], $key);
        foreach (['SCANNER', 'NAVIGATOR', 'DECODER'] as $index => $role) {
            $assign($index, $role, $index + 1, 'initial-role-'.$index);
        }
        $this->contend(fn () => $assign(3, 'AR_OPERATOR', 4, 'fourth-role-key'), fn () => $assign(4, 'AR_OPERATOR', 4, 'fifth-role-key'), 'ACTION_NOT_ALLOWED');
        $this->assertSame(4, PlayerRoleAssignment::whereNull('released_at')->count());
        $this->assertFalse(PlayerRoleAssignment::where('game_session_player_id', $players[4]->id)->exists());
    }

    public function test_relay_double_advance_cannot_skip_the_next_role(): void
    {
        [$admin, , $instance, $player, $node] = $this->runtimeFixture($this->runtimeGraph('Relay', ['roles' => ['SCANNER', 'DECODER']]));
        $other = $instance->registration->players()->whereKeyNot($player->id)->firstOrFail();
        foreach ([[$player, 'SCANNER'], [$other, 'DECODER']] as $index => [$member, $role]) {
            app(RuntimeEngine::class)->execute($admin, 'assign_role', $node->public_id, ['reason' => 'Relay role', 'player_id' => $member->public_id, 'role_code' => $role, 'slot_no' => $index + 1], 'relay-assign-'.$index);
        }
        $this->runtimeAction($player, $node, 'start_node');
        $this->contend(fn () => $this->runtimeAction($player, $node, 'submit_action', ['acknowledged' => true], 'relay-advance-one'),
            fn () => $this->runtimeAction($player, $node, 'submit_action', ['acknowledged' => true], 'relay-advance-two'), 'ACTION_NOT_ALLOWED');
        $this->assertSame(1, $node->fresh()->state_json['relay_index']);
        $this->assertSame('ACTIVE', $node->fresh()->status);
        $this->runtimeAction($other, $node, 'submit_action', ['acknowledged' => true]);
        $this->assertSame('COMPLETED', $node->fresh()->status);
    }

    public function test_role_reassignment_serializes_against_runtime_submission(): void
    {
        [$admin, , $instance, $player, $node] = $this->runtimeFixture();
        $other = $instance->registration->players()->whereKeyNot($player->id)->firstOrFail();
        $engine = app(RuntimeEngine::class);
        $engine->execute($admin, 'assign_role', $node->public_id, ['reason' => 'Initial decoder', 'player_id' => $player->public_id, 'role_code' => 'DECODER', 'slot_no' => 1], 'initial-decoder-key');
        $this->runtimeAction($player, $node, 'start_node');
        $assignment = PlayerRoleAssignment::sole();
        $swap = function () use ($engine, $admin, $node, $assignment, $other): void {
            $engine->execute($admin, 'release_role', $node->public_id, ['reason' => 'Reassignment', 'assignment_id' => $assignment->public_id], 'swap-release-key');
            $engine->execute($admin, 'assign_role', $node->public_id, ['reason' => 'Reassignment', 'player_id' => $other->public_id, 'role_code' => 'DECODER', 'slot_no' => 1], 'swap-assign-key');
        };

        $this->contend($swap, fn () => $this->runtimeAction($player, $node, 'submit_action', ['answer' => 'Secret']), 'ACTION_NOT_ALLOWED');

        $this->assertSame('ACTIVE', $node->fresh()->status);
        $this->assertSame($other->id, PlayerRoleAssignment::whereNull('released_at')->sole()->game_session_player_id);
        $this->assertNotNull($assignment->fresh()->released_at);
    }

    public function test_late_join_serializes_with_stage_role_assignment(): void
    {
        [$admin, $session, , , $node] = $this->runtimeFixture(playerCount: 5, readyCount: 4);
        $late = $session->players()->where('participation_status', 'JOINED')->sole();
        $device = UserDevice::factory()->create(['user_id' => $late->user_id]);
        $sessions = app(SessionService::class);
        $sessions->execute($late->user, 'bindDevice', ['device_id' => $device->public_id], $session->public_id);

        $this->contend(fn () => $sessions->execute($late->user, 'lateJoin', [], $session->public_id, requestDevice: $device),
            fn () => app(RuntimeEngine::class)->execute($admin, 'assign_role', $node->public_id,
                ['reason' => 'Late join role', 'player_id' => $late->public_id, 'role_code' => 'DECODER', 'slot_no' => 1], 'late-stage-assignment'));

        $this->assertSame('ACTIVE', $late->fresh()->participation_status);
        $this->assertSame($late->id, PlayerRoleAssignment::whereNull('released_at')->sole()->game_session_player_id);
        $this->assertDatabaseCount('game_session_players', 5);
        $this->assertDatabaseCount('game_session_roster_snapshots', 1);
    }
}

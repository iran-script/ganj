<?php

namespace Tests\Feature;

use App\Models\TeamMember;
use App\Models\UserDevice;
use App\Services\Games\SessionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\GameSessionFixtures;
use Tests\TestCase;

class GameSessionConcurrencyTest extends TestCase
{
    use DatabaseMigrations,GameSessionFixtures;

    public static function races(): array
    {
        return ['capacity' => ['capacity', 'SESSION_TEAM_CAPACITY_REACHED'], 'duplicate' => ['duplicate', 'CONFLICT'],
            'same user' => ['same user', 'CONFLICT'], 'remove register' => ['remove register', 'CONFLICT'],
            'roster ready' => ['roster ready', 'RESOURCE_NOT_FOUND'], 'bind bind' => ['bind bind', 'ACTION_NOT_ALLOWED'],
            'rebind ready' => ['rebind ready', 'DEVICE_NOT_BOUND'], 'start start' => ['start start', null],
            'start remove' => ['start remove', 'STATE_TRANSITION_INVALID'], 'finish cancel' => ['finish cancel', 'STATE_TRANSITION_INVALID'],
            'pause finish' => ['pause finish', null], 'close register' => ['close register', 'RESOURCE_NOT_FOUND']];
    }

    #[DataProvider('races')]
    public function test_competing_commands_wait_for_the_session_boundary_and_recheck_committed_state(string $race, ?string $expected): void
    {
        $fake = $this->adapters();
        [$admin,,$session] = $this->sessionFixture();
        $team = $this->registerFixture($session);
        $service = app(SessionService::class);
        if (in_array($race, ['start start', 'start remove', 'finish cancel', 'pause finish'], true)) {
            $this->readyFixture($session);
            $service->execute($admin, 'closeRegistration', [], $session->public_id);
            if (in_array($race, ['finish cancel', 'pause finish'], true)) {
                $service->execute($admin, 'start', [], $session->public_id, key: 'race-setup-start');
            }
        }
        $first = [];
        $second = [];
        switch ($race) {
            case 'capacity':
                for ($i = 0; $i < 3; $i++) {
                    $this->registerFixture($session);
                }
                $one = $this->teamFixture();
                $two = $this->teamFixture();
                $first = [$one->captain, 'registerTeam', ['team_id' => $one->public_id], $session->public_id];
                $second = [$two->captain, 'registerTeam', ['team_id' => $two->public_id], $session->public_id];
                break;
            case 'duplicate':
                $one = $this->teamFixture();
                $first = [$one->captain, 'registerTeam', ['team_id' => $one->public_id], $session->public_id];
                $second = [$admin, 'registerTeam', ['team_id' => $one->public_id, 'reason' => 'Operational registration'], $session->public_id];
                break;
            case 'same user':
                $one = $this->teamFixture();
                $two = $this->teamFixture();
                TeamMember::factory()->create(['team_id' => $two->id, 'user_id' => $one->captain_user_id]);
                $first = [$one->captain, 'registerTeam', ['team_id' => $one->public_id], $session->public_id];
                $second = [$two->captain, 'registerTeam', ['team_id' => $two->public_id], $session->public_id];
                break;
            case 'remove register':
                $first = [$admin, 'removeTeam', ['reason' => 'Withdraw registration'], $session->public_id, $team->public_id];
                $second = [$team->captain, 'registerTeam', ['team_id' => $team->public_id], $session->public_id];
                break;
            case 'roster ready':
                $player = $session->players()->where('user_id', '!=', $team->captain_user_id)->first();
                $device = UserDevice::factory()->create(['user_id' => $player->user_id]);
                $service->execute($player->user, 'bindDevice', ['device_id' => $device->public_id], $session->public_id);
                $selected = $team->activeMembers()->where('user_id', '!=', $player->user_id)->with('user')->get()->pluck('user.public_id')->all();
                $first = [$team->captain, 'syncRoster', ['user_ids' => $selected], $session->public_id, $team->public_id];
                $second = [$player->user, 'ready', ['ready' => true], $session->public_id, null, null, $device];
                break;
            case 'bind bind':
            case 'rebind ready':
                $player = $session->players()->first();
                $one = UserDevice::factory()->create(['user_id' => $player->user_id]);
                $two = UserDevice::factory()->create(['user_id' => $player->user_id]);
                if ($race === 'bind bind') {
                    $first = [$player->user, 'bindDevice', ['device_id' => $one->public_id], $session->public_id];
                    $second = [$player->user, 'bindDevice', ['device_id' => $two->public_id], $session->public_id];
                } else {
                    $service->execute($player->user, 'bindDevice', ['device_id' => $one->public_id], $session->public_id);
                    $first = [$admin, 'rebindDevice', ['user_id' => $player->user->public_id, 'device_id' => $two->public_id, 'reason' => 'Staff replacement'], $session->public_id, null, 'race-rebind'];
                    $second = [$player->user, 'ready', ['ready' => true], $session->public_id, null, null, $one];
                }
                break;
            case 'start start':
                $first = $second = [$admin, 'start', [], $session->public_id, null, 'race-double-start'];
                break;
            case 'start remove':
                $first = [$admin, 'start', [], $session->public_id, null, 'race-start'];
                $second = [$team->captain, 'removeTeam', [], $session->public_id, $team->public_id];
                break;
            case 'finish cancel':
                $first = [$admin, 'finish', [], $session->public_id, null, 'race-finish'];
                $second = [$admin, 'cancel', ['reason' => 'Emergency', 'emergency' => true], $session->public_id, null, 'race-cancel'];
                break;
            case 'pause finish':
                $first = [$admin, 'pause', [], $session->public_id, null, 'race-pause'];
                $second = [$admin, 'finish', [], $session->public_id, null, 'race-finish'];
                break;
            case 'close register':
                $new = $this->teamFixture();
                $first = [$admin, 'closeRegistration', [], $session->public_id];
                $second = [$new->captain, 'registerTeam', ['team_id' => $new->public_id], $session->public_id];
                break;
        }
        $default = DB::getDefaultConnection();
        config(['database.connections.session_probe' => config('database.connections.'.$default)]);
        $primary = DB::connection($default);
        $probe = DB::connection('session_probe');
        $probe->statement("SET lock_timeout = '200ms'");
        $primary->beginTransaction();
        try {
            app(SessionService::class)->execute(...$first);
            DB::setDefaultConnection('session_probe');
            try {
                app(SessionService::class)->execute(...$second);
                $this->fail('The competitor bypassed the Session lock.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            }
            DB::setDefaultConnection($default);
            $primary->commit();
            DB::setDefaultConnection('session_probe');
            if ($expected === null) {
                app(SessionService::class)->execute(...$second);
            } else {
                $this->assertDomainError($expected, fn () => app(SessionService::class)->execute(...$second));
            }
        } finally {
            DB::setDefaultConnection($default);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('session_probe');
        }
        $this->assertLessThanOrEqual(5, $session->teams()->whereNotIn('status', ['REMOVED', 'DISQUALIFIED'])->count());
        $this->assertSame($session->players()->count(), $session->players()->distinct()->count('user_id'));
        if ($race === 'start start') {
            $this->assertSame(1, $fake->bootstrapCalls);
            $this->assertDatabaseCount('game_session_roster_snapshots', 1);
            $this->assertSame(1, DB::table('outbox_events')->where('topic', 'game_session.started.v1')->count());
        }
        if ($race === 'pause finish' || $race === 'finish cancel') {
            $this->assertSame('FINISHED', $session->fresh()->status);
        }
    }
}

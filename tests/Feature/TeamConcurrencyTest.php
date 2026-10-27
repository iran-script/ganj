<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Teams\TeamService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public static function races(): array
    {
        return [['capacity', 'TEAM_FULL'], ['last invite use', 'INVITE_EXHAUSTED'],
            ['duplicate join', 'JOIN_REQUEST_REPLAY'], ['transfer vs leave', 'ACTION_NOT_ALLOWED'],
            ['transfer vs remove', 'AUTHORIZATION_DENIED'], ['revoke vs join', 'INVITE_REVOKED'],
            ['transfer vs transfer', 'AUTHORIZATION_DENIED']];
    }

    #[DataProvider('races')]
    public function test_competing_mutations_wait_then_recheck_committed_state(string $race, string $expected): void
    {
        $team = Team::factory()->create();
        $captain = $team->captain;
        $firstJoiner = User::factory()->create();
        $secondJoiner = User::factory()->create();
        $invite = TeamInvite::factory()->code('race-code')->create(['team_id' => $team->id, 'max_uses' => $race === 'last invite use' ? 1 : null]);
        if ($race === 'capacity') {
            TeamMember::factory()->count(6)->create(['team_id' => $team->id]);
        }
        if (str_starts_with($race, 'transfer')) {
            TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $firstJoiner->id]);
            TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $secondJoiner->id]);
        }
        $first = match ($race) {
            'revoke vs join' => [$captain, 'revokeInvite', [], $team->public_id, $invite->public_id],
            'transfer vs leave', 'transfer vs remove', 'transfer vs transfer' => [$captain, 'transfer', ['user_id' => $firstJoiner->public_id], $team->public_id],
            default => [$firstJoiner, 'join', ['invite_code' => 'race-code']],
        };
        $second = match ($race) {
            'duplicate join' => [$firstJoiner, 'join', ['invite_code' => 'race-code']],
            'transfer vs leave' => [$firstJoiner, 'leave', [], $team->public_id],
            'transfer vs remove' => [$captain, 'remove', [], $team->public_id, $firstJoiner->public_id],
            'transfer vs transfer' => [$captain, 'transfer', ['user_id' => $secondJoiner->public_id], $team->public_id],
            default => [$secondJoiner, 'join', ['invite_code' => 'race-code']],
        };
        $firstOperation = fn (): ?array => app(TeamService::class)->execute(...$first);
        $secondOperation = fn (): ?array => app(TeamService::class)->execute(...$second);
        if (in_array($race, ['capacity', 'last invite use'], true)) {
            $service = app(TeamService::class);
            $firstRequest = $service->requestTeamJoin($firstJoiner, 'race-code');
            $secondRequest = $service->requestTeamJoin($secondJoiner, 'race-code');
            $firstOperation = fn (): array => $service->decideJoinRequest($captain, $team->public_id, $firstRequest['id'], true);
            $secondOperation = fn (): array => $service->decideJoinRequest($captain, $team->public_id, $secondRequest['id'], true);
        }
        $default = DB::getDefaultConnection();
        config(['database.connections.team_lock_probe' => config('database.connections.'.$default)]);
        $primary = DB::connection($default);
        $probe = DB::connection('team_lock_probe');
        $probe->statement("SET lock_timeout = '200ms'");
        $primary->beginTransaction();
        try {
            $firstResult = $firstOperation();
            DB::setDefaultConnection('team_lock_probe');
            try {
                $secondOperation();
                $this->fail('The second connection bypassed the transaction boundary.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            }
            DB::setDefaultConnection($default);
            $primary->commit();
            DB::setDefaultConnection('team_lock_probe');
            if ($race === 'duplicate join') {
                $secondResult = $secondOperation();
                $this->assertSame('JOIN_REQUEST_REPLAY', $expected);
                $this->assertSame($firstResult['id'], $secondResult['id']);
            } else {
                try {
                    $secondOperation();
                    $this->fail('The losing operation did not recheck the committed state.');
                } catch (ApiException $exception) {
                    $this->assertSame($expected, $exception->errorCode);
                } catch (AuthorizationException) {
                    $this->assertSame('AUTHORIZATION_DENIED', $expected);
                }
            }
        } finally {
            DB::setDefaultConnection($default);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('team_lock_probe');
        }
        $current = $team->fresh();
        $this->assertLessThanOrEqual(8, $current->activeMembers()->count());
        $this->assertSame(1, $current->activeMembers()->where('user_id', $current->captain_user_id)->count());
        if ($race === 'duplicate join') {
            $this->assertSame(0, $invite->fresh()->uses_count);
            $this->assertDatabaseCount('team_join_requests', 1);
            $this->assertDatabaseCount('outbox_events', 0);
            $this->assertDatabaseCount('audit_events', 0);
        } else {
            $this->assertSame(str_starts_with($race, 'transfer') || $race === 'revoke vs join' ? 0 : 1, $invite->fresh()->uses_count);
            $this->assertDatabaseCount('outbox_events', 1);
            $this->assertDatabaseCount('audit_events', 1);
        }
    }
}

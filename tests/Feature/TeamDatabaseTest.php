<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidWrites(): array
    {
        return [['duplicate membership', '23505'], ['duplicate public id', '23505'], ['duplicate hash', '23505'],
            ['negative uses', '23514'], ['excess uses', '23514'], ['zero maximum', '23514'],
            ['invalid status', '23514'], ['invalid member status', '23514'], ['missing user', '23503'],
            ['missing organization', '23503'], ['captain not member', '23514'], ['captain left', '23514'],
            ['history rewrite', '23514'], ['history delete', '23514'], ['team delete', '23514'],
            ['event rewrite', '23514'], ['event delete', '23514'], ['unarchive', '23514'], ['media unavailable', '23514']];
    }

    #[DataProvider('invalidWrites')]
    public function test_database_rejects_invalid_team_writes(string $case, string $sqlState): void
    {
        $team = Team::factory()->create();
        $invite = TeamInvite::factory()->create(['team_id' => $team->id, 'max_uses' => 1]);
        $former = TeamMember::factory()->left()->create(['team_id' => $team->id]);
        $outsider = User::factory()->create();
        DB::table('team_member_events')->insert(['team_id' => $team->id, 'user_id' => $team->captain_user_id, 'event_type' => 'team.member_joined.v1', 'actor_id' => $team->captain_user_id, 'payload' => '{}', 'occurred_at' => now()]);
        if ($case === 'unarchive') {
            DB::table('teams')->where('id', $team->id)->update(['status' => 'ARCHIVED', 'archived_at' => now()]);
        }
        try {
            DB::transaction(function () use ($case, $team, $invite, $former, $outsider): void {
                match ($case) {
                    'duplicate membership' => TeamMember::factory()->create(['team_id' => $team->id, 'user_id' => $team->captain_user_id]),
                    'duplicate public id' => Team::factory()->create(['public_id' => $team->public_id]),
                    'duplicate hash' => TeamInvite::factory()->create(['code_hash' => $invite->code_hash]),
                    'negative uses' => DB::table('team_invites')->where('id', $invite->id)->update(['uses_count' => -1]),
                    'excess uses' => DB::table('team_invites')->where('id', $invite->id)->update(['uses_count' => 2]),
                    'zero maximum' => DB::table('team_invites')->where('id', $invite->id)->update(['max_uses' => 0]),
                    'invalid status' => DB::table('teams')->where('id', $team->id)->update(['status' => 'DELETED']),
                    'invalid member status' => DB::table('team_members')->where('team_id', $team->id)->where('status', 'ACTIVE')->update(['status' => 'UNKNOWN']),
                    'missing user' => DB::table('teams')->where('id', $team->id)->update(['captain_user_id' => 99999999]),
                    'missing organization' => DB::table('teams')->where('id', $team->id)->update(['organization_id' => 99999999]),
                    'captain not member' => DB::table('teams')->where('id', $team->id)->update(['captain_user_id' => $outsider->id]),
                    'captain left' => DB::table('team_members')->where('team_id', $team->id)->where('status', 'ACTIVE')->update(['status' => 'LEFT', 'left_at' => now()]),
                    'history rewrite' => DB::table('team_members')->where('id', $former->id)->update(['left_at' => now()->addDay()]),
                    'history delete' => DB::table('team_members')->where('id', $former->id)->delete(),
                    'team delete' => DB::table('teams')->where('id', $team->id)->delete(),
                    'event rewrite' => DB::table('team_member_events')->update(['payload' => '{"changed":true}']),
                    'event delete' => DB::table('team_member_events')->delete(),
                    'unarchive' => DB::table('teams')->where('id', $team->id)->update(['status' => 'ACTIVE', 'archived_at' => null]),
                    'media unavailable' => DB::table('teams')->where('id', $team->id)->update(['avatar_media_id' => 1]),
                };
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('The database accepted an invalid write: '.$case);
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
        }
        $this->assertModelExists($team);
        $this->assertModelExists($former);
    }

    public function test_same_user_can_have_membership_in_multiple_persistent_teams(): void
    {
        $user = User::factory()->create();
        Team::factory()->count(2)->create(['captain_user_id' => $user->id]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame(2, TeamMember::where('user_id', $user->id)->where('status', 'ACTIVE')->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\TeamInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\GameSessionFixtures;
use Tests\TestCase;

class TeamInvitationApiTest extends TestCase
{
    use GameSessionFixtures, RefreshDatabase;

    public function test_captain_can_search_and_send_a_private_registered_player_invitation(): void
    {
        $team = $this->teamFixture(1);
        $recipient = User::factory()->create(['mobile' => '09120000001']);
        $this->authenticateGame($team->captain);

        $this->getJson('/api/v1/players/'.$recipient->public_id)->assertOk()
            ->assertExactJson(['data' => ['id' => $recipient->public_id, 'name' => $recipient->name]])
            ->assertJsonMissingPath('data.mobile');

        $response = $this->postJson('/api/v1/teams/'.$team->public_id.'/player-invitations', [
            'user_id' => $recipient->public_id,
        ])->assertCreated()->assertJsonPath('data.recipient.id', $recipient->public_id)
            ->assertJsonPath('data.decision', 'PENDING');

        $this->assertDatabaseHas('team_invites', [
            'public_id' => $response->json('data.id'),
            'recipient_user_id' => $recipient->id,
            'decision' => 'PENDING',
        ]);
        $this->assertDatabaseMissing('team_members', ['team_id' => $team->id, 'user_id' => $recipient->id, 'status' => 'ACTIVE']);
        $replay = $this->postJson('/api/v1/teams/'.$team->public_id.'/player-invitations', [
            'user_id' => $recipient->public_id,
        ])->assertCreated();
        $this->assertSame($response->json('data.id'), $replay->json('data.id'));
    }

    public function test_recipient_can_accept_invitation_and_become_a_team_member(): void
    {
        $team = $this->teamFixture(1);
        $recipient = User::factory()->create();
        $this->authenticateGame($team->captain);
        $inviteId = $this->postJson('/api/v1/teams/'.$team->public_id.'/player-invitations', [
            'user_id' => $recipient->public_id,
        ])->assertCreated()->json('data.id');

        $this->authenticateGame($recipient);
        $this->getJson('/api/v1/me/team-invitations')->assertOk()
            ->assertJsonPath('data.0.id', $inviteId)
            ->assertJsonPath('data.0.team.id', $team->public_id)
            ->assertJsonMissingPath('data.0.captain.mobile');

        $this->postJson('/api/v1/me/team-invitations/'.$inviteId.'/accept')->assertOk()
            ->assertJsonPath('data.status', 'ACCEPTED')
            ->assertJsonPath('data.team.member_count', 2)
            ->assertJsonPath('data.team.eligible_for_game', false);
        $this->postJson('/api/v1/me/team-invitations/'.$inviteId.'/accept')->assertOk()
            ->assertJsonPath('data.status', 'ACCEPTED');

        $this->assertDatabaseCount('team_members', 2);
        $this->assertDatabaseHas('team_invites', ['public_id' => $inviteId, 'decision' => 'ACCEPTED', 'uses_count' => 1]);
    }

    public function test_declining_invitation_never_adds_a_membership_and_wrong_user_cannot_respond(): void
    {
        $team = $this->teamFixture(1);
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        $this->authenticateGame($team->captain);
        $inviteId = $this->postJson('/api/v1/teams/'.$team->public_id.'/player-invitations', [
            'user_id' => $recipient->public_id,
        ])->assertCreated()->json('data.id');

        $this->authenticateGame($outsider);
        $this->postJson('/api/v1/me/team-invitations/'.$inviteId.'/accept')->assertNotFound();
        $this->authenticateGame($recipient);
        $this->postJson('/api/v1/me/team-invitations/'.$inviteId.'/decline')->assertOk()
            ->assertJsonPath('data.status', 'DECLINED');
        $this->assertDatabaseMissing('team_members', ['team_id' => $team->id, 'user_id' => $recipient->id, 'status' => 'ACTIVE']);
        $this->postJson('/api/v1/me/team-invitations/'.$inviteId.'/accept')->assertConflict()
            ->assertJsonPath('error.code', 'INVITATION_NOT_PENDING');
    }

    public function test_only_captain_can_invite_and_four_explicit_acceptances_make_team_booking_eligible(): void
    {
        $team = $this->teamFixture(1);
        $recipients = User::factory()->count(3)->create();
        $this->authenticateGame($team->captain);
        $this->getJson('/api/v1/players/'.$recipients[0]->public_id)->assertOk();
        $inviteIds = $recipients->map(fn (User $recipient): string => $this->postJson('/api/v1/teams/'.$team->public_id.'/player-invitations', [
            'user_id' => $recipient->public_id,
        ])->assertCreated()->json('data.id'));

        $this->authenticateGame($recipients[0]);
        $this->postJson('/api/v1/teams/'.$team->public_id.'/player-invitations', [
            'user_id' => User::factory()->create()->public_id,
        ])->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        foreach ($recipients as $index => $recipient) {
            $this->authenticateGame($recipient);
            $this->postJson('/api/v1/me/team-invitations/'.$inviteIds[$index].'/accept')->assertOk();
        }

        $this->authenticateGame($team->captain);
        $this->getJson('/api/v1/teams/'.$team->public_id)->assertOk()
            ->assertJsonPath('data.member_count', 4)
            ->assertJsonPath('data.eligible_for_game', true)
            ->assertJsonMissingPath('data.members.0.mobile');
        $this->assertDatabaseCount('team_members', 4);
        $this->assertSame(3, TeamInvite::query()->where('team_id', $team->id)->where('decision', 'ACCEPTED')->count());
    }

    public function test_team_full_and_duplicate_membership_are_rejected(): void
    {
        $team = $this->teamFixture(8);
        $recipient = User::factory()->create();
        $this->authenticateGame($team->captain);
        $this->postJson('/api/v1/teams/'.$team->public_id.'/player-invitations', [
            'user_id' => $recipient->public_id,
        ])->assertConflict()->assertJsonPath('error.code', 'TEAM_FULL');

        $member = $team->activeMembers()->where('user_id', '!=', $team->captain_user_id)->firstOrFail()->user;
        $this->postJson('/api/v1/teams/'.$team->public_id.'/player-invitations', [
            'user_id' => $member->public_id,
        ])->assertConflict()->assertJsonPath('error.code', 'ALREADY_TEAM_MEMBER');
    }

    public function test_invite_code_requires_captain_approval_before_membership_and_is_safe_to_share(): void
    {
        $team = $this->teamFixture(2);
        $player = User::factory()->create(['mobile' => '09121112222']);
        $this->authenticateGame($team->captain);
        $invite = $this->postJson('/api/v1/teams/'.$team->public_id.'/invites', [
            'expires_at' => now()->addDays(7)->toISOString(),
            'max_uses' => 1,
        ])->assertCreated()->json('data');
        $code = $invite['invite_code'];
        $this->assertSame(16, strlen($code));

        $this->authenticateGame($player);
        $preview = $this->getJson('/api/v1/team-invites/'.$code)->assertOk()
            ->assertJsonPath('data.team.name', $team->name)
            ->assertJsonPath('data.member_count', 2)
            ->assertJsonMissingPath('data.team.id')
            ->assertJsonMissingPath('data.team.captain.id')
            ->assertJsonMissingPath('data.team.captain.mobile');
        $request = $this->postJson('/api/v1/teams/join', ['invite_code' => $code], ['Idempotency-Key' => 'join-'.$player->public_id])
            ->assertOk()->assertJsonPath('data.status', 'PENDING');
        $requestId = $request->json('data.id');
        $this->assertDatabaseMissing('team_members', ['team_id' => $team->id, 'user_id' => $player->id, 'status' => 'ACTIVE']);

        $nonCaptain = $team->activeMembers()->where('user_id', '!=', $team->captain_user_id)->firstOrFail()->user;
        $this->authenticateGame($nonCaptain);
        $this->getJson('/api/v1/teams/'.$team->public_id.'/join-requests')->assertForbidden();
        $this->authenticateGame($team->captain);
        $this->getJson('/api/v1/teams/'.$team->public_id.'/join-requests')->assertOk()
            ->assertJsonPath('data.0.id', $requestId)
            ->assertJsonPath('data.0.player.id', $player->public_id)
            ->assertJsonMissingPath('data.0.player.mobile');
        $this->postJson('/api/v1/teams/'.$team->public_id.'/join-requests/'.$requestId.'/accept')->assertOk()
            ->assertJsonPath('data.status', 'ACCEPTED');
        $this->assertDatabaseHas('team_members', ['team_id' => $team->id, 'user_id' => $player->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('team_invites', ['public_id' => $invite['id'], 'uses_count' => 1]);
        $this->authenticateGame($player);
        $this->getJson('/api/v1/team-invites/'.$code)->assertConflict()
            ->assertJsonPath('error.code', 'INVITE_EXHAUSTED');
    }

    public function test_code_join_is_idempotent_and_rejects_invalid_expired_and_revoked_invites(): void
    {
        $team = $this->teamFixture(1);
        $player = User::factory()->create();
        $this->authenticateGame($team->captain);
        $invite = $this->postJson('/api/v1/teams/'.$team->public_id.'/invites')->assertCreated()->json('data');
        $this->authenticateGame($player);
        $this->getJson('/api/v1/team-invites/'.str_repeat('a', 64))->assertNotFound();
        $first = $this->postJson('/api/v1/teams/join', ['invite_code' => $invite['invite_code']])->assertOk()->json('data.id');
        $second = $this->postJson('/api/v1/teams/join', ['invite_code' => $invite['invite_code']])->assertOk()->json('data.id');
        $this->assertSame($first, $second);
        $this->assertDatabaseMissing('team_members', ['team_id' => $team->id, 'user_id' => $player->id, 'status' => 'ACTIVE']);

        $this->authenticateGame($team->captain);
        $this->deleteJson('/api/v1/teams/'.$team->public_id.'/invites/'.$invite['id'])->assertNoContent();
        $this->authenticateGame(User::factory()->create());
        $this->getJson('/api/v1/team-invites/'.$invite['invite_code'])->assertConflict()
            ->assertJsonPath('error.code', 'INVITE_REVOKED');

        $this->authenticateGame($team->captain);
        $expired = $this->postJson('/api/v1/teams/'.$team->public_id.'/invites')->assertCreated()->json('data');
        TeamInvite::query()->where('public_id', $expired['id'])->update(['expires_at' => now()->subMinute()]);
        $this->authenticateGame(User::factory()->create());
        $this->getJson('/api/v1/team-invites/'.$expired['invite_code'])->assertConflict()
            ->assertJsonPath('error.code', 'INVITE_EXPIRED');

        $declinee = User::factory()->create();
        $this->authenticateGame($team->captain);
        $declineInvite = $this->postJson('/api/v1/teams/'.$team->public_id.'/invites')->assertCreated()->json('data');
        $this->authenticateGame($declinee);
        $requestId = $this->postJson('/api/v1/teams/join', ['invite_code' => $declineInvite['invite_code']])
            ->assertOk()->json('data.id');
        $this->authenticateGame($team->captain);
        $this->postJson('/api/v1/teams/'.$team->public_id.'/join-requests/'.$requestId.'/decline')->assertOk()
            ->assertJsonPath('data.status', 'DECLINED');
        $this->assertDatabaseMissing('team_members', ['team_id' => $team->id, 'user_id' => $declinee->id, 'status' => 'ACTIVE']);
    }

    public function test_code_invites_reject_full_and_booking_locked_teams(): void
    {
        $fullTeam = $this->teamFixture(8);
        $this->authenticateGame($fullTeam->captain);
        $fullInvite = $this->postJson('/api/v1/teams/'.$fullTeam->public_id.'/invites')->assertCreated()->json('data');
        $this->authenticateGame(User::factory()->create());
        $this->getJson('/api/v1/team-invites/'.$fullInvite['invite_code'])->assertConflict()
            ->assertJsonPath('error.code', 'TEAM_FULL');

        $lockedTeam = $this->teamFixture(4);
        $game = Game::factory()->create(['pricing' => ['mode' => 'PER_ROOM', 'amount' => 1000, 'currency' => 'IRR']]);
        $this->authenticateGame($lockedTeam->captain);
        $this->postJson('/api/v1/games/'.$game->public_id.'/bookings', [
            'team_id' => $lockedTeam->public_id,
            'room_name' => 'Test room',
        ], ['Idempotency-Key' => 'locked-team-booking-0001'])->assertCreated()
            ->assertJsonPath('data.status', 'AWAITING_PAYMENT');
        $lockedInvite = $this->postJson('/api/v1/teams/'.$lockedTeam->public_id.'/invites')
            ->assertCreated()->json('data');

        $requestingPlayer = User::factory()->create();
        $this->authenticateGame($requestingPlayer);
        $this->getJson('/api/v1/team-invites/'.$lockedInvite['invite_code'])->assertConflict()
            ->assertJsonPath('error.code', 'TEAM_LOCKED');
        $this->postJson('/api/v1/teams/join', ['invite_code' => $lockedInvite['invite_code']])
            ->assertConflict()->assertJsonPath('error.code', 'TEAM_LOCKED');
        $this->assertDatabaseMissing('team_join_requests', ['team_id' => $lockedTeam->id, 'user_id' => $requestingPlayer->id]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\GameSession;
use App\Models\LiveChallenge;
use App\Models\LiveChallengeSubmission;
use App\Models\OrganizationMember;
use App\Models\ScoreEvent;
use App\Models\TeamScore;
use App\Models\User;
use App\Services\GameMaster\LiveChallengeService;
use App\Services\Games\GameMasterAssignments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class LiveChallengeBehaviorTest extends TestCase
{
    use RefreshDatabase, RuntimeFixtures;

    public function test_immediate_activation_ack_start_submit_and_reward_are_idempotent(): void
    {
        [$admin, $session, , $player] = $this->runtimeFixture();
        $service = app(LiveChallengeService::class);
        $input = ['type' => 'TEAM_OBJECTIVE', 'objective' => 'Find the guide', 'duration_seconds' => 60,
            'targets' => [['type' => 'PLAYER', 'id' => $player->public_id]], 'reward' => ['score_delta' => 25]];
        $challenge = $service->create($admin, $session->public_id, $input, 'challenge-create-key');
        $service->create($admin, $session->public_id, $input, 'challenge-create-key');

        $this->assertSame('ACTIVE', $challenge['status']);
        $this->assertDatabaseCount('live_challenges', 1);
        $this->assertArrayNotHasKey('reward_config', $service->show($player->user, $challenge['id']));
        $service->transition($player->user, $challenge['id'], 'ack', [], 'challenge-ack-key');
        $service->transition($player->user, $challenge['id'], 'start', [], 'challenge-start-key');
        $completed = $service->transition($player->user, $challenge['id'], 'submit', ['acknowledged' => true, 'reward' => ['score_delta' => 999]], 'challenge-submit-key');
        $retry = $service->transition($player->user, $challenge['id'], 'submit', ['acknowledged' => true, 'reward' => ['score_delta' => 999]], 'challenge-submit-key');

        $this->assertSame('COMPLETED', $completed['status']);
        $this->assertSame('COMPLETED', $retry['status']);
        $this->assertDatabaseCount('live_challenge_submissions', 1);
        $this->assertSame(25, TeamScore::sole()->score);
        $this->assertSame(1, ScoreEvent::where('source_type', 'LIVE_CHALLENGE_REWARD')->count());
        $this->assertDomainError('STATE_TRANSITION_INVALID', fn () => $service->transition($admin, $challenge['id'], 'cancel', ['reason' => 'Too late'], 'terminal-cancel-key'));
        $this->assertSame('COMPLETED', LiveChallenge::sole()->status);
    }

    public function test_expiry_is_durable_and_cannot_be_acknowledged_or_rewarded(): void
    {
        $this->freezeTime();
        [$admin, $session, , $player] = $this->runtimeFixture();
        $service = app(LiveChallengeService::class);
        $challenge = $service->create($admin, $session->public_id, ['type' => 'MESSAGE', 'message' => 'Hello', 'duration_seconds' => 1,
            'targets' => [['type' => 'PLAYER', 'id' => $player->public_id]]], 'expiry-create-key');
        $this->travel(1)->seconds();

        $this->assertDomainError('STATE_TRANSITION_INVALID', fn () => $service->transition($player->user, $challenge['id'], 'ack', [], 'expiry-ack-key'));

        $this->assertSame('EXPIRED', LiveChallenge::sole()->status);
        $this->assertDatabaseHas('live_challenge_targets', ['status' => 'EXPIRED']);
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'live_challenge.expired.v1')->count());
        $this->assertDomainError('STATE_TRANSITION_INVALID', fn () => $service->transition($player->user, $challenge['id'], 'ack', [], 'expiry-ack-retry'));
        $this->assertSame(0, LiveChallengeSubmission::count());
        $this->assertSame(0, ScoreEvent::count());
    }

    public function test_player_cannot_create_and_cancelled_team_challenge_cannot_submit(): void
    {
        [$admin, $session, $instance, $player] = $this->runtimeFixture();
        $service = app(LiveChallengeService::class);
        $input = ['type' => 'MESSAGE', 'message' => 'Team notice', 'targets' => [['type' => 'TEAM', 'id' => $instance->registration->team->public_id]]];
        $this->assertDomainError('AUTHORIZATION_DENIED', fn () => $service->create($player->user, $session->public_id, $input, 'player-create-key'));
        $challenge = $service->create($admin, $session->public_id, $input, 'team-challenge-key');
        $service->transition($player->user, $challenge['id'], 'ack', [], 'team-ack-key');

        $service->transition($admin, $challenge['id'], 'cancel', ['reason' => 'Field safety'], 'team-cancel-key');

        $this->assertDomainError('STATE_TRANSITION_INVALID', fn () => $service->transition($player->user, $challenge['id'], 'submit', ['acknowledged' => true], 'cancel-submit-key'));
        $this->assertSame('CANCELLED', LiveChallenge::sole()->status);
        $this->assertSame(0, LiveChallengeSubmission::count());
    }

    public function test_only_assigned_gamemaster_can_access_the_control_room(): void
    {
        [$admin, $session] = $this->runtimeFixture();
        $gm = User::factory()->create();
        OrganizationMember::factory()->create(['user_id' => $gm->id, 'organization_id' => $session->organization_id, 'role' => 'GAMEMASTER']);
        $service = app(LiveChallengeService::class);
        $this->assertDomainError('RESOURCE_NOT_FOUND', fn () => $service->controlRoom($gm, $session->public_id));
        app(GameMasterAssignments::class)->assign($admin, $gm, $session->public_id, true, 'Control room assignment');

        $result = $service->controlRoom($gm, $session->public_id);

        $this->assertCount(1, $result['teams']);
        $other = GameSession::factory()->create();
        $this->assertDomainError('RESOURCE_NOT_FOUND', fn () => $service->controlRoom($gm, $other->public_id));
    }
}

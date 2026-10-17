<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\GameSessionFixtures;
use Tests\TestCase;

class GameRoomApiTest extends TestCase
{
    use GameSessionFixtures,RefreshDatabase;

    public function test_captain_can_create_room_and_retry_with_same_idempotency_key(): void
    {
        $game = Game::factory()->create();
        $team = $this->eligibleTeam();
        $this->authenticateGame($team->captain);

        $payload = ['name' => 'تیم‌های شهر', 'team_id' => $team->public_id];
        $this->postJson('/api/v1/games/'.$game->public_id.'/rooms', $payload, ['Idempotency-Key' => 'room-create-001'])
            ->assertCreated()->assertJsonPath('data.status', 'OPEN')->assertJsonPath('data.team_count', 1);
        $roomId = $this->getJson('/api/v1/games/'.$game->public_id.'/rooms')->assertOk()->json('data.0.id');

        $this->postJson('/api/v1/games/'.$game->public_id.'/rooms', $payload, ['Idempotency-Key' => 'room-create-001'])
            ->assertCreated()->assertJsonPath('data.id', $roomId);
        $this->assertDatabaseCount('game_rooms', 1);
        $this->assertDatabaseCount('game_room_teams', 1);
    }

    public function test_only_captains_can_create_rooms_and_joining_respects_room_capacity(): void
    {
        $game = Game::factory()->create();
        $ownerTeam = $this->eligibleTeam();
        $this->authenticateGame($ownerTeam->captain);
        $room = $this->postJson('/api/v1/games/'.$game->public_id.'/rooms', [
            'name' => 'اتاق مرکزی', 'team_id' => $ownerTeam->public_id,
        ], ['Idempotency-Key' => 'room-create-002'])->assertCreated()->json('data');

        $anotherTeam = $this->eligibleTeam();
        $this->authenticateGame($anotherTeam->captain);
        $this->postJson('/api/v1/rooms/'.$room['id'].'/teams', ['team_id' => $anotherTeam->public_id], ['Idempotency-Key' => 'room-join-002'])
            ->assertOk()->assertJsonPath('data.team_count', 2);

        foreach (range(3, 5) as $number) {
            $team = $this->eligibleTeam();
            $this->authenticateGame($team->captain);
            $this->postJson('/api/v1/rooms/'.$room['id'].'/teams', ['team_id' => $team->public_id], ['Idempotency-Key' => 'room-join-'.$number])
                ->assertOk()->assertJsonPath('data.team_count', $number);
        }
        $sixthTeam = $this->eligibleTeam();
        $this->authenticateGame($sixthTeam->captain);
        $this->postJson('/api/v1/rooms/'.$room['id'].'/teams', ['team_id' => $sixthTeam->public_id], ['Idempotency-Key' => 'room-at-capacity'])
            ->assertConflict();

        $ordinaryMember = $anotherTeam->activeMembers()->where('user_id', '!=', $anotherTeam->captain_user_id)->firstOrFail()->user;
        $this->authenticateGame($ordinaryMember);
        $this->postJson('/api/v1/rooms/'.$room['id'].'/teams', ['team_id' => $anotherTeam->public_id], ['Idempotency-Key' => 'room-join-denied'])
            ->assertForbidden();
    }

    public function test_room_rejects_unknown_fields_and_ineligible_teams(): void
    {
        $game = Game::factory()->create();
        $team = Team::factory()->create();
        $this->authenticateGame($team->captain);

        $this->postJson('/api/v1/games/'.$game->public_id.'/rooms', [
            'name' => 'تیم کوچک', 'team_id' => $team->public_id,
        ], ['Idempotency-Key' => 'room-ineligible'])->assertConflict();
        $this->postJson('/api/v1/games/'.$game->public_id.'/rooms', [
            'name' => 'اتاق', 'team_id' => $team->public_id, 'status' => 'RUNNING',
        ], ['Idempotency-Key' => 'room-unknown'])->assertUnprocessable();
    }

    private function eligibleTeam(): Team
    {
        $team = Team::factory()->create();
        TeamMember::factory()->count(3)->create(['team_id' => $team->id]);

        return $team->fresh();
    }
}

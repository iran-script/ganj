<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\GameSessionFixtures;
use Tests\TestCase;

class GameBookingApiTest extends TestCase
{
    use GameSessionFixtures, RefreshDatabase;

    public function test_free_booking_creates_a_room_and_replays_idempotently(): void
    {
        $game = Game::factory()->create(['pricing' => ['mode' => 'FREE', 'amount' => 0, 'currency' => 'IRR']]);
        $team = $this->teamFixture();
        $this->authenticateGame($team->captain);
        $headers = ['Idempotency-Key' => 'free-booking-0001'];
        $payload = ['team_id' => $team->public_id, 'room_name' => 'اتاق تست'];

        $first = $this->postJson('/api/v1/games/'.$game->public_id.'/bookings', $payload, $headers)
            ->assertCreated()->assertJsonPath('data.status', 'ROOM_CREATED')->assertJsonPath('data.amount', 0);
        $replay = $this->postJson('/api/v1/games/'.$game->public_id.'/bookings', $payload, $headers)
            ->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('game_bookings', 1);
        $this->assertDatabaseCount('game_rooms', 1);
        $this->assertDatabaseCount('game_room_teams', 1);
        $this->assertCount(4, $replay->json('data.participants'));
    }

    public function test_per_room_booking_waits_for_test_payment_before_creating_room(): void
    {
        $game = Game::factory()->create(['pricing' => ['mode' => 'PER_ROOM', 'amount' => 125000, 'currency' => 'IRR']]);
        $team = $this->teamFixture();
        $this->authenticateGame($team->captain);

        $booking = $this->postJson('/api/v1/games/'.$game->public_id.'/bookings', [
            'team_id' => $team->public_id, 'room_name' => 'اتاق تست',
        ], ['Idempotency-Key' => 'per-room-booking-001'])->assertCreated()
            ->assertJsonPath('data.status', 'AWAITING_PAYMENT')->assertJsonPath('data.amount', 125000);
        $orderId = collect($booking->json('data.participants'))->firstWhere('user_id', $team->captain->public_id)['payment_order']['id'];
        $this->assertDatabaseCount('game_rooms', 0);

        $this->postJson('/api/v1/payment-orders/'.$orderId.'/test-confirm')->assertOk()
            ->assertJsonPath('data.status', 'ROOM_CREATED')->assertJsonPath('data.room.name', 'اتاق تست');
        $this->assertDatabaseCount('game_rooms', 1);
        $this->assertDatabaseHas('payment_orders', ['public_id' => $orderId, 'status' => 'PAID', 'amount' => 125000]);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_per_player_booking_keeps_a_fixed_roster_and_waits_for_every_payment(): void
    {
        $game = Game::factory()->create(['pricing' => ['mode' => 'PER_PLAYER', 'amount' => 30000, 'currency' => 'IRR']]);
        $team = $this->teamFixture();
        $captain = $team->captain;
        $this->authenticateGame($captain);

        $booking = $this->postJson('/api/v1/games/'.$game->public_id.'/bookings', [
            'team_id' => $team->public_id, 'room_name' => 'اتاق تیم',
        ], ['Idempotency-Key' => 'per-player-booking-001'])->assertCreated()
            ->assertJsonPath('data.status', 'AWAITING_PAYMENT')->assertJsonPath('data.amount', 120000);
        $participants = collect($booking->json('data.participants'));
        $this->assertCount(4, $participants);
        $this->assertSame(4, $participants->pluck('payment_order.id')->filter()->unique()->count());

        $members = $team->activeMembers()->with('user')->get()->values();
        foreach ($members as $index => $member) {
            $this->authenticateGame($member->user);
            $orderId = $participants->firstWhere('user_id', $member->user->public_id)['payment_order']['id'];
            $response = $this->postJson('/api/v1/payment-orders/'.$orderId.'/test-confirm');
            if ($index < $members->count() - 1) {
                $response->assertOk()->assertJsonPath('data.status', 'PARTIALLY_PAID');
                $this->assertDatabaseCount('game_rooms', 0);
            } else {
                $response->assertOk()->assertJsonPath('data.status', 'ROOM_CREATED');
            }
        }

        $this->getJson('/api/v1/bookings/'.$booking->json('data.id'))->assertOk()
            ->assertJsonPath('data.status', 'ROOM_CREATED')->assertJsonPath('data.room.name', 'اتاق تیم');
        $this->assertDatabaseCount('game_rooms', 1);
        $this->assertDatabaseCount('payment_transactions', 4);
    }

    public function test_booking_requires_authentication_captain_role_and_eligible_team(): void
    {
        $game = Game::factory()->create(['pricing' => ['mode' => 'FREE', 'amount' => 0, 'currency' => 'IRR']]);
        $team = $this->teamFixture(3);
        $payload = ['team_id' => $team->public_id, 'room_name' => 'اتاق تست'];

        $this->postJson('/api/v1/games/'.$game->public_id.'/bookings', $payload, ['Idempotency-Key' => 'unauth-booking-1'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        $member = $team->activeMembers()->where('user_id', '!=', $team->captain_user_id)->firstOrFail()->user;
        $this->authenticateGame($member);
        $this->postJson('/api/v1/games/'.$game->public_id.'/bookings', $payload, ['Idempotency-Key' => 'member-booking-001'])
            ->assertForbidden()->assertJsonPath('error.code', 'CAPTAIN_REQUIRED');
        $this->assertDatabaseCount('game_bookings', 0);
    }

    public function test_payment_history_requires_authentication_and_only_returns_the_payers_orders(): void
    {
        $game = Game::factory()->create(['pricing' => ['mode' => 'PER_PLAYER', 'amount' => 30000, 'currency' => 'IRR']]);
        $team = $this->teamFixture();
        $captain = $team->captain;

        $this->getJson('/api/v1/me/payments')->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');

        $this->authenticateGame($captain);
        $booking = $this->postJson('/api/v1/games/'.$game->public_id.'/bookings', [
            'team_id' => $team->public_id,
            'room_name' => 'Payment history test',
        ], ['Idempotency-Key' => 'payment-history-owner-001'])->assertCreated();
        $participants = collect($booking->json('data.participants'));
        $captainOrderId = $participants->firstWhere('user_id', $captain->public_id)['payment_order']['id'];

        $this->getJson('/api/v1/me/payments')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $captainOrderId);

        $otherMember = $team->activeMembers()->where('user_id', '!=', $captain->id)->firstOrFail()->user;
        $otherOrderId = $participants->firstWhere('user_id', $otherMember->public_id)['payment_order']['id'];
        $this->authenticateGame($otherMember);

        $this->getJson('/api/v1/me/payments')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $otherOrderId)
            ->assertJsonMissing(['id' => $captainOrderId]);

        $outsider = User::factory()->create();
        $this->authenticateGame($outsider);
        $this->getJson('/api/v1/me/payments')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_paid_games_cannot_bypass_booking_through_the_legacy_room_endpoint(): void
    {
        $game = Game::factory()->create(['pricing' => ['mode' => 'PER_ROOM', 'amount' => 85000, 'currency' => 'IRR']]);
        $team = $this->teamFixture();
        $this->authenticateGame($team->captain);

        $this->postJson('/api/v1/games/'.$game->public_id.'/rooms', [
            'name' => 'اتاق دور زدن پرداخت', 'team_id' => $team->public_id,
        ], ['Idempotency-Key' => 'room-payment-bypass-001'])->assertConflict()
            ->assertJsonPath('error.code', 'BOOKING_REQUIRED');
        $this->assertDatabaseCount('game_rooms', 0);
    }
}

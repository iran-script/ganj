<?php

namespace App\Services\Games;

use App\Exceptions\ApiException;
use App\Models\BookingParticipant;
use App\Models\Game;
use App\Models\GameBooking;
use App\Models\GameRoom;
use App\Models\GameRoomTeam;
use App\Models\PaymentOrder;
use App\Models\PaymentTransaction;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Teams\TeamScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GameBookingService
{
    public function __construct(private GameScope $games, private TeamScope $teams, private AuditService $audit) {}

    public function listing(User $actor): array
    {
        return GameBooking::query()->where(function (Builder $query) use ($actor): void {
            $query->where('captain_user_id', $actor->id)
                ->orWhereHas('participants', fn (Builder $participants): Builder => $participants->where('user_id', $actor->id));
        })->with(['game', 'team', 'room', 'participants.user'])->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (GameBooking $booking): array => $booking->publicProfile($actor))->all();
    }

    public function show(User $actor, string $bookingId): array
    {
        $booking = $this->resolveForViewer($actor, $bookingId);

        return $booking->publicProfile($actor);
    }

    public function payments(User $actor): array
    {
        return PaymentOrder::query()->where('payer_user_id', $actor->id)->with(['booking.game'])->orderByDesc('created_at')->limit(100)->get()
            ->map(fn (PaymentOrder $order): array => [
                'id' => $order->public_id,
                'booking_id' => $order->booking->public_id,
                'game' => ['id' => $order->booking->game->public_id, 'name' => $order->booking->game->name],
                'amount' => (int) $order->amount,
                'currency' => $order->currency,
                'status' => $order->status,
                'provider' => $order->provider,
                'expires_at' => $order->expires_at?->toISOString(),
                'paid_at' => $order->paid_at?->toISOString(),
                'created_at' => $order->created_at?->toISOString(),
            ])->all();
    }

    public function create(User $actor, string $gameId, array $input, ?string $requestKey): array
    {
        if (! is_string($requestKey) || strlen($requestKey) < 8 || strlen($requestKey) > 255) {
            throw new ApiException('VALIDATION_FAILED', 'An Idempotency-Key header is required.', [], 422);
        }

        $key = hash('sha256', $actor->public_id.':'.$requestKey);
        $canonicalInput = $input;
        ksort($canonicalInput);
        $fingerprint = hash('sha256', json_encode([$gameId, $canonicalInput], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $gameId, $input, $key, $fingerprint): array {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $game = $this->games->resolve($actor, $gameId, lock: true);
            if (! $game instanceof Game) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Game not found.', [], 404);
            }
            if ($game->status !== 'ACTIVE' || $game->organization->status !== 'ACTIVE') {
                throw new ApiException('GAME_UNAVAILABLE', 'This game is not available for booking.', [], 409);
            }
            $team = $this->teams->resolve($actor, $input['team_id']);
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail()->load(['organization', 'activeMembers.user']);
            if ($team->captain_user_id !== $actor->id || ! $team->activeMembers->contains('user_id', $actor->id)) {
                throw new ApiException('CAPTAIN_REQUIRED', 'Only the active team captain can create a booking.', [], 403);
            }
            if ($team->status !== 'ACTIVE' || ($team->organization_id !== null && $team->organization_id !== $game->organization_id)) {
                throw new ApiException('TEAM_NOT_ELIGIBLE', 'This team cannot book the selected game.', [], 409);
            }
            $members = $team->activeMembers;
            if ($members->count() < 4 || $members->count() > 8) {
                throw new ApiException('TEAM_NOT_ELIGIBLE', 'A team must have 4 to 8 active members to book a game.', ['member_count' => $members->count(), 'minimum' => 4, 'maximum' => 8], 409);
            }

            $pricing = $this->pricing($game);
            $existing = GameBooking::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new ApiException('IDEMPOTENCY_KEY_REUSED', 'The idempotency key was already used with a different booking request.', [], 409);
                }

                return $this->resolveForViewer($actor, $existing->public_id)->publicProfile($actor);
            }
            $activeBooking = GameBooking::query()->where('game_id', $game->id)->where('team_id', $team->id)
                ->whereIn('status', ['AWAITING_PAYMENT', 'PARTIALLY_PAID', 'PAID', 'ROOM_CREATED', 'REFUND_PENDING'])->lockForUpdate()->first();
            if ($activeBooking && $this->expireBooking($actor, $activeBooking)) {
                $activeBooking = null;
            }
            if ($activeBooking) {
                throw new ApiException('BOOKING_ALREADY_EXISTS', 'This team already has an active booking for this game.', ['booking_id' => $activeBooking->public_id], 409);
            }
            if ($pricing['max_active_reservations'] !== null) {
                $activeCount = GameBooking::query()->where('game_id', $game->id)
                    ->whereIn('status', ['AWAITING_PAYMENT', 'PARTIALLY_PAID', 'PAID', 'ROOM_CREATED', 'REFUND_PENDING'])->count();
                if ($activeCount >= $pricing['max_active_reservations']) {
                    throw new ApiException('BOOKING_CAPACITY_REACHED', 'No more reservations are available for this game.', [], 409);
                }
            }

            $roster = $members->map(fn ($member): array => ['user_id' => $member->user->public_id, 'name' => $member->user->name])->values()->all();
            $total = $pricing['mode'] === 'PER_PLAYER' ? $this->multiply($pricing['amount'], count($roster)) : $pricing['amount'];
            $now = now();
            $booking = new GameBooking;
            $booking->forceFill([
                'game_id' => $game->id,
                'team_id' => $team->id,
                'captain_user_id' => $actor->id,
                'status' => $pricing['mode'] === 'FREE' ? 'PAID' : 'AWAITING_PAYMENT',
                'payment_mode' => $pricing['mode'],
                'unit_amount' => $pricing['amount'],
                'total_amount' => $total,
                'currency' => $pricing['currency'],
                'roster_snapshot' => $roster,
                'pricing_snapshot' => [...$pricing, 'room_name' => $input['room_name']],
                'idempotency_key' => $key,
                'request_fingerprint' => $fingerprint,
                'payment_deadline_at' => $pricing['payment_deadline_minutes'] === null ? null : $now->copy()->addMinutes($pricing['payment_deadline_minutes']),
                'created_at' => $now,
                'updated_at' => $now,
            ])->save();

            if ($pricing['mode'] === 'FREE') {
                foreach ($members as $member) {
                    $participant = new BookingParticipant;
                    $participant->forceFill(['game_booking_id' => $booking->id, 'user_id' => $member->user_id, 'amount' => 0, 'status' => 'PAID', 'paid_at' => $now, 'created_at' => $now, 'updated_at' => $now])->save();
                }
                $this->createRoom($booking, $game, $team, $actor, $input['room_name']);
            } else {
                foreach ($members as $member) {
                    $participant = new BookingParticipant;
                    $participant->forceFill([
                        'game_booking_id' => $booking->id,
                        'user_id' => $member->user_id,
                        'amount' => $pricing['mode'] === 'PER_PLAYER' ? $pricing['amount'] : 0,
                        'status' => 'PENDING',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->save();
                }
                $payers = $pricing['mode'] === 'PER_ROOM' ? collect([$members->firstWhere('user_id', $actor->id)]) : $members;
                foreach ($payers as $member) {
                    $payerId = $member->user_id;
                    $amount = $pricing['mode'] === 'PER_ROOM' ? $total : $pricing['amount'];
                    $order = new PaymentOrder;
                    $order->forceFill([
                        'game_booking_id' => $booking->id,
                        'payer_user_id' => $payerId,
                        'amount' => $amount,
                        'currency' => $pricing['currency'],
                        'status' => 'PENDING',
                        'provider' => null,
                        'idempotency_key' => hash('sha256', $booking->public_id.':'.$payerId),
                        'expires_at' => $booking->payment_deadline_at,
                    ])->save();
                    if ($pricing['mode'] === 'PER_PLAYER') {
                        $participant = BookingParticipant::query()->where('game_booking_id', $booking->id)->where('user_id', $member->user_id)->firstOrFail();
                        $participant->forceFill(['payment_order_id' => $order->id])->save();
                    } else {
                        $participant = BookingParticipant::query()->where('game_booking_id', $booking->id)->where('user_id', $actor->id)->firstOrFail();
                        $participant->forceFill(['payment_order_id' => $order->id])->save();
                    }
                }
            }

            $this->audit->record('game_booking.created', 'game_booking', $booking->public_id, context: [
                'game_id' => $game->public_id,
                'team_id' => $team->public_id,
                'payment_mode' => $pricing['mode'],
                'amount' => $total,
                'currency' => $pricing['currency'],
            ], actorId: $actor->id, organizationId: $game->organization_id);

            return $this->loadBooking($booking)->publicProfile($actor);
        }, 3);
    }

    public function startCheckout(User $actor, string $orderId): array
    {
        $order = $this->resolveOrderForPayer($actor, $orderId);
        $booking = GameBooking::query()->whereKey($order->game_booking_id)->firstOrFail();
        $this->expireBooking($actor, $booking);
        $order->refresh();
        if ($order->status === 'EXPIRED') {
            throw new ApiException('PAYMENT_ORDER_EXPIRED', 'The payment deadline has passed.', [], 409);
        }
        if ($order->status !== 'PENDING') {
            throw new ApiException('PAYMENT_ORDER_NOT_PAYABLE', 'This payment order is not awaiting payment.', [], 409);
        }
        if (app()->environment('testing')) {
            return ['order_id' => $order->public_id, 'status' => $order->status, 'provider' => 'TEST_ONLY', 'test_only' => true];
        }
        throw new ApiException('PAYMENT_PROVIDER_UNAVAILABLE', 'Online payment is not configured for this environment.', [], 503);
    }

    public function confirmTestPayment(User $actor, string $orderId): array
    {
        if (! app()->environment('testing')) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Test payments are available only in the automated test environment.', [], 404);
        }

        return DB::transaction(function () use ($actor, $orderId): array {
            $order = $this->resolveOrderForPayer($actor, $orderId, lock: true);
            $booking = GameBooking::query()->whereKey($order->game_booking_id)->lockForUpdate()->firstOrFail();
            $this->expireBooking($actor, $booking);
            $order->refresh();
            if ($order->status === 'EXPIRED') {
                return $this->loadBooking($booking)->publicProfile($actor);
            }
            if ($order->status !== 'PAID') {
                $now = now();
                $reference = 'test:'.Str::uuid();
                $order->forceFill(['status' => 'PAID', 'provider' => 'TEST_ONLY', 'provider_reference' => $reference, 'paid_at' => $now])->save();
                $transaction = new PaymentTransaction;
                $transaction->forceFill([
                    'payment_order_id' => $order->id,
                    'provider' => 'TEST_ONLY',
                    'provider_reference' => $reference,
                    'status' => 'PAID',
                    'amount' => $order->amount,
                    'currency' => $order->currency,
                    'response_fingerprint' => hash('sha256', $reference.':'.$order->amount.':'.$order->currency),
                    'occurred_at' => $now,
                    'created_at' => $now,
                ])->save();
                BookingParticipant::query()->where('payment_order_id', $order->id)->update(['status' => 'PAID', 'paid_at' => $now]);
                $allPaid = $booking->paymentOrders()->where('status', '!=', 'PAID')->doesntExist();
                $booking->forceFill(['status' => $allPaid ? 'PAID' : 'PARTIALLY_PAID', 'updated_at' => $now])->save();
                if ($allPaid) {
                    BookingParticipant::query()->where('game_booking_id', $booking->id)->update(['status' => 'PAID', 'paid_at' => $now]);
                    $game = Game::query()->with('organization')->findOrFail($booking->game_id);
                    $team = Team::query()->with(['activeMembers.user'])->findOrFail($booking->team_id);
                    if ($this->rosterMatches($booking, $team)) {
                        $roomName = $booking->pricing_snapshot['room_name'] ?? $team->name;
                        $this->createRoom($booking, $game, $team, $booking->captain, $roomName);
                    } else {
                        $this->audit->record('game_booking.roster_changed_after_payment', 'game_booking', $booking->public_id,
                            context: ['room_created' => false], actorId: $actor->id, organizationId: $game->organization_id);
                    }
                }
                $this->audit->record('payment.test_confirmed', 'payment_order', $order->public_id, context: ['amount' => $order->amount, 'currency' => $order->currency], actorId: $actor->id, organizationId: $booking->game->organization_id);
            }

            return $this->loadBooking($booking)->publicProfile($actor);
        }, 3);
    }

    private function createRoom(GameBooking $booking, Game $game, Team $team, User $actor, string $name): void
    {
        $alreadyInRoom = GameRoomTeam::query()->where('team_id', $team->id)
            ->whereHas('room', fn (Builder $rooms): Builder => $rooms->where('game_id', $game->id)->where('status', 'OPEN'))
            ->exists();
        if ($alreadyInRoom) {
            throw new ApiException('CONFLICT', 'This team already belongs to an open room for this game.', [], 409);
        }
        if (! $this->rosterMatches($booking, $team)) {
            throw new ApiException('BOOKING_ROSTER_CHANGED', 'The team roster changed after the price was quoted.', [], 409);
        }
        $room = new GameRoom;
        $room->forceFill(['game_id' => $game->id, 'name' => $name, 'status' => 'OPEN', 'team_capacity' => 5, 'created_by' => $actor->id])->save();
        $membership = new GameRoomTeam;
        $membership->forceFill(['game_room_id' => $room->id, 'team_id' => $team->id, 'added_by' => $actor->id, 'joined_at' => now()])->save();
        $booking->forceFill(['status' => 'ROOM_CREATED', 'game_room_id' => $room->id, 'updated_at' => now()])->save();
    }

    private function expireBooking(User $actor, GameBooking $booking): bool
    {
        if ($booking->payment_deadline_at === null || now()->lt($booking->payment_deadline_at)) {
            return false;
        }
        if ($booking->status === 'AWAITING_PAYMENT') {
            $booking->paymentOrders()->whereIn('status', ['PENDING', 'PROCESSING'])->update(['status' => 'EXPIRED', 'updated_at' => now()]);
            $booking->participants()->where('status', 'PENDING')->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            $booking->forceFill(['status' => 'EXPIRED', 'updated_at' => now()])->save();
            $this->audit->record('game_booking.expired', 'game_booking', $booking->public_id,
                context: ['payment_deadline_at' => $booking->payment_deadline_at->toISOString()], actorId: $actor->id, organizationId: $booking->game->organization_id);

            return true;
        }
        if ($booking->status === 'PARTIALLY_PAID') {
            $booking->paymentOrders()->whereIn('status', ['PENDING', 'PROCESSING'])->update(['status' => 'EXPIRED', 'updated_at' => now()]);
            $booking->participants()->where('status', 'PAID')->update(['status' => 'REFUND_PENDING', 'updated_at' => now()]);
            $booking->forceFill(['status' => 'REFUND_PENDING', 'updated_at' => now()])->save();
            $this->audit->record('game_booking.partial_payment_expired', 'game_booking', $booking->public_id,
                context: ['refund_resolution_required' => true], actorId: $actor->id, organizationId: $booking->game->organization_id);
        }

        return false;
    }

    private function rosterMatches(GameBooking $booking, Team $team): bool
    {
        $quoted = collect($booking->roster_snapshot)->pluck('user_id')->sort()->values()->all();
        $current = $team->activeMembers()->with('user')->get()->map(fn ($member): string => $member->user->public_id)->sort()->values()->all();

        return $quoted === $current;
    }

    private function pricing(Game $game): array
    {
        $pricing = $game->pricing;
        if (! is_array($pricing) || ! in_array($pricing['mode'] ?? null, ['FREE', 'PAID', 'PER_ROOM', 'PER_PLAYER'], true)
            || ! is_int($pricing['amount'] ?? null) || ! is_string($pricing['currency'] ?? null)
            || preg_match('/^[A-Z]{3}$/', $pricing['currency']) !== 1
            || (($pricing['mode'] === 'FREE') !== ($pricing['amount'] === 0))
            || $pricing['amount'] > 999999999999) {
            throw new ApiException('GAME_PRICING_UNAVAILABLE', 'The game does not have a valid booking price configured.', [], 409);
        }
        if (($pricing['booking_available'] ?? true) !== true) {
            throw new ApiException('BOOKING_UNAVAILABLE', 'Bookings are not open for this game.', [], 409);
        }

        $mode = $pricing['mode'] === 'PAID' ? 'PER_ROOM' : $pricing['mode'];
        if (isset($pricing['effective_at']) && now()->lt(Carbon::parse($pricing['effective_at']))) {
            throw new ApiException('BOOKING_UNAVAILABLE', 'The configured price is not effective yet.', [], 409);
        }

        return [
            'mode' => $mode,
            'amount' => $pricing['amount'],
            'currency' => $pricing['currency'],
            'booking_available' => $pricing['booking_available'] ?? true,
            'payment_deadline_minutes' => isset($pricing['payment_deadline_minutes']) ? max(1, min(10080, (int) $pricing['payment_deadline_minutes'])) : null,
            'refund_policy' => $pricing['refund_policy'] ?? null,
            'cancellation_policy' => $pricing['cancellation_policy'] ?? null,
            'max_active_reservations' => isset($pricing['max_active_reservations']) ? (int) $pricing['max_active_reservations'] : null,
            'effective_at' => $pricing['effective_at'] ?? null,
        ];
    }

    private function multiply(int $amount, int $count): int
    {
        if ($amount > intdiv(PHP_INT_MAX, $count)) {
            throw new ApiException('GAME_PRICING_INVALID', 'The configured booking price exceeds the supported amount.', [], 409);
        }

        return $amount * $count;
    }

    private function resolveForViewer(User $actor, string $bookingId): GameBooking
    {
        $booking = Str::isUuid($bookingId) ? GameBooking::query()->with(['game', 'team', 'room', 'participants.user'])
            ->where('public_id', $bookingId)->where(function (Builder $query) use ($actor): void {
                $query->where('captain_user_id', $actor->id)
                    ->orWhereHas('participants', fn (Builder $participants): Builder => $participants->where('user_id', $actor->id));
            })->first() : null;
        if (! $booking) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Booking not found.', [], 404);
        }

        return $booking;
    }

    private function resolveOrderForPayer(User $actor, string $orderId, bool $lock = false): PaymentOrder
    {
        $order = Str::isUuid($orderId) ? PaymentOrder::query()->where('public_id', $orderId)->where('payer_user_id', $actor->id)->first() : null;
        if (! $order) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Payment order not found.', [], 404);
        }
        if ($lock) {
            $order = PaymentOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        }

        return $order;
    }

    private function loadBooking(GameBooking $booking): GameBooking
    {
        return GameBooking::query()->with(['game', 'team', 'room', 'participants.user'])->whereKey($booking->id)->firstOrFail();
    }
}

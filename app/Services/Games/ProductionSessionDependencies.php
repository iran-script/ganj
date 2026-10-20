<?php

namespace App\Services\Games;

use App\Exceptions\ApiException;
use App\Models\Game;
use App\Models\GameSession;
use App\Models\GameSessionPlayer;
use App\Models\ScenarioVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Persisted free admission; paid admission remains closed until a payment adapter exists. */
class ProductionSessionDependencies implements BillingGate, CancellationResolution, PricingSnapshotProvider, ScenarioAssignmentReadiness
{
    public function validatePricing(?array $pricing): array
    {
        $validator = Validator::make(['pricing' => $pricing], [
            'pricing' => 'required|array:mode,amount,currency,booking_available,payment_deadline_minutes,refund_policy,cancellation_policy,max_active_reservations,effective_at',
            'pricing.mode' => 'required|in:FREE,PAID,PER_ROOM,PER_PLAYER',
            'pricing.amount' => 'required|integer|min:0|max:999999999999',
            'pricing.currency' => 'required|string|regex:/^[A-Z]{3}$/',
            'pricing.booking_available' => 'sometimes|boolean',
            'pricing.payment_deadline_minutes' => 'sometimes|nullable|integer|min:1|max:10080',
            'pricing.refund_policy' => 'sometimes|nullable|string|max:4000',
            'pricing.cancellation_policy' => 'sometimes|nullable|string|max:4000',
            'pricing.max_active_reservations' => 'sometimes|nullable|integer|min:1|max:100000',
            'pricing.effective_at' => 'sometimes|nullable|date',
        ]);
        if ($validator->fails() || ($pricing['mode'] === 'FREE' ? (int) $pricing['amount'] !== 0 : (int) $pricing['amount'] <= 0)) {
            throw new ApiException('SESSION_BILLING_NOT_READY', 'Explicit valid game pricing is required.', [], 409);
        }

        return [
            'mode' => $pricing['mode'],
            'amount' => (int) $pricing['amount'],
            'currency' => $pricing['currency'],
            'booking_available' => (bool) ($pricing['booking_available'] ?? true),
            'payment_deadline_minutes' => isset($pricing['payment_deadline_minutes']) ? (int) $pricing['payment_deadline_minutes'] : null,
            'refund_policy' => $pricing['refund_policy'] ?? null,
            'cancellation_policy' => $pricing['cancellation_policy'] ?? null,
            'max_active_reservations' => isset($pricing['max_active_reservations']) ? (int) $pricing['max_active_reservations'] : null,
            'effective_at' => $pricing['effective_at'] ?? null,
        ];
    }

    public function publishedVersion(int $versionId, int $organizationId): ScenarioVersion
    {
        $version = ScenarioVersion::with('scenario')->find($versionId);
        if (! $version || $version->status !== 'PUBLISHED' || $version->scenario->organization_id !== $organizationId) {
            throw new ApiException('SCENARIO_NOT_ASSIGNED', 'A published scenario version from this organization is required.', [], 409);
        }

        return $version;
    }

    public function pricing(GameSession $session): array
    {
        $snapshot = DB::table('session_configuration_snapshots')->where('game_session_id', $session->id)->first();

        return $this->validatePricing($snapshot ? json_decode($snapshot->pricing, true, flags: JSON_THROW_ON_ERROR) : $session->game()->firstOrFail()->pricing);
    }

    /** Opening registration pins the offered price and version atomically before players join. */
    public function registrationReady(GameSession $session): bool
    {
        return DB::transaction(function () use ($session): bool {
            $game = Game::whereKey($session->game_id)->lockForUpdate()->firstOrFail();
            $session = GameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status !== 'DRAFT' || $game->status !== 'ACTIVE' || $game->organization->status !== 'ACTIVE' || $session->organization_id !== $game->organization_id) {
                return false;
            }
            try {
                $pricing = $this->validatePricing($game->pricing);
                $version = $this->publishedVersion((int) $game->scenario_version_id, $session->organization_id);
            } catch (ApiException) {
                return false;
            }
            if ($pricing['mode'] !== 'FREE') {
                return false;
            }
            if (! DB::table('session_configuration_snapshots')->where('game_session_id', $session->id)->exists()) {
                DB::table('session_configuration_snapshots')->insert([
                    'public_id' => (string) Str::uuid(), 'game_session_id' => $session->id,
                    'scenario_version_id' => $version->id, 'pricing' => json_encode($pricing, JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
            }

            return $this->pricing($session)['mode'] === 'FREE';
        });
    }

    public function assignments(GameSession $session, array $roster): array
    {
        $snapshot = DB::table('session_configuration_snapshots')->where('game_session_id', $session->id)->first();
        $version = $this->publishedVersion((int) ($snapshot?->scenario_version_id ?? $session->game()->firstOrFail()->scenario_version_id), $session->organization_id);

        return array_fill_keys(array_column($roster, 'team_id'), $version->public_id);
    }

    public function evaluate(GameSession $session, array $roster, string $stage, ?GameSessionPlayer $player = null): array
    {
        try {
            $pricing = $this->pricing($session);
        } catch (ApiException) {
            return ['status' => 'PENDING', 'reference' => null];
        }
        $snapshot = DB::table('session_configuration_snapshots')->where('game_session_id', $session->id)->first();
        if ($pricing['mode'] !== 'FREE' || ! $snapshot) {
            return ['status' => 'PENDING', 'reference' => null];
        }
        $reference = 'free-exemption:'.$snapshot->public_id;
        $participations = [];
        foreach ($roster as $team) {
            foreach ($team['players'] as $participant) {
                $participations[$participant['user_id']] = $reference.':'.$participant['player_id'];
            }
        }

        return ['status' => 'PASS', 'reference' => $reference, 'participation_references' => $participations];
    }

    public function freeze(GameSession $session, array $roster): array
    {
        if ($this->evaluate($session, $roster, 'start')['status'] !== 'PASS') {
            throw new ApiException('SESSION_BILLING_NOT_READY', 'Billing is not satisfied.', [], 409);
        }

        return [DB::table('session_configuration_snapshots')->where('game_session_id', $session->id)->sole()->public_id];
    }

    public function resolveCancellation(GameSession $session, string $reason, string $commandId): array
    {
        return ['status' => $this->evaluate($session, [], 'cancel')['status'] === 'PASS' ? 'NOT_APPLICABLE' : 'PENDING'];
    }
}

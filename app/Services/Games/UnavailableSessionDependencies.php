<?php

namespace App\Services\Games;

use App\Exceptions\ApiException;
use App\Models\GameSession;
use App\Models\GameSessionPlayer;

class UnavailableSessionDependencies implements BillingGate, CancellationResolution, PricingSnapshotProvider, RuntimeBootstrap, ScenarioAssignmentReadiness
{
    public function evaluate(GameSession $session, array $roster, string $stage, ?GameSessionPlayer $player = null): array
    {
        return ['status' => 'PENDING', 'reference' => null];
    }

    public function assignments(GameSession $session, array $roster): array
    {
        throw new ApiException('SCENARIO_NOT_ASSIGNED', 'Scenario readiness adapter is unavailable.', [], 409);
    }

    public function registrationReady(GameSession $session): bool
    {
        return false;
    }

    public function freeze(GameSession $session, array $roster): array
    {
        throw new ApiException('SESSION_BILLING_NOT_READY', 'Pricing adapter is unavailable.', [], 409);
    }

    public function bootstrap(GameSession $session, array $frozenRosters, string $commandId): array
    {
        throw new ApiException('SESSION_NOT_READY', 'Runtime bootstrap adapter is unavailable.', [], 409);
    }

    public function resolveCancellation(GameSession $session, string $reason, string $commandId): array
    {
        throw new ApiException('SESSION_NOT_READY', 'Cancellation resolution adapter is unavailable.', [], 409);
    }
}

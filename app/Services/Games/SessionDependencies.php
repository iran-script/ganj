<?php

namespace App\Services\Games;

use App\Exceptions\ApiException;
use App\Models\GameSession;
use App\Models\GameSessionPlayer;

class SessionDependencies
{
    public function __construct(public BillingGate $billing, public ScenarioAssignmentReadiness $scenarios, public PricingSnapshotProvider $pricing, public RuntimeBootstrap $runtime, public CancellationResolution $cancellation) {}

    public function billingPass(GameSession $session, array $roster, string $stage, ?GameSessionPlayer $player = null): array
    {
        $result = $this->billing->evaluate($session, $roster, $stage, $player);
        if (($result['status'] ?? null) !== 'PASS') {
            throw new ApiException('SESSION_BILLING_NOT_READY', 'Billing readiness is not confirmed.', ['stage' => $stage, 'status' => $result['status'] ?? 'PENDING'], 409);
        }

        return $result;
    }

    public function assignments(GameSession $session, array $roster): array
    {
        $result = $this->scenarios->assignments($session, $roster);
        foreach ($roster as $team) {
            if (! is_string($result[$team['team_id']] ?? null) || $result[$team['team_id']] === '') {
                throw new ApiException('SCENARIO_NOT_ASSIGNED', 'Every Team needs a valid pinned scenario version.', [], 409);
            }
        }

        return $result;
    }
}

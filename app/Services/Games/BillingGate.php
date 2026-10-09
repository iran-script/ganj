<?php

namespace App\Services\Games;

use App\Models\GameSession;
use App\Models\GameSessionPlayer;

interface BillingGate
{
    /** @return array{status: 'PASS'|'FAIL'|'PENDING', reference: ?string, participation_references?: array<string, string>} */
    public function evaluate(GameSession $session, array $roster, string $stage, ?GameSessionPlayer $player = null): array;
}

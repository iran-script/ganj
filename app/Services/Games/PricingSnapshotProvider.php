<?php

namespace App\Services\Games;

use App\Models\GameSession;

interface PricingSnapshotProvider
{
    public function registrationReady(GameSession $session): bool;

    /** Freeze pricing inside the caller transaction; return immutable public references. */
    public function freeze(GameSession $session, array $roster): array;
}

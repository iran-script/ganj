<?php

namespace App\Services\Games;

use App\Models\GameSession;

interface CancellationResolution
{
    /** Resolve financial/runtime effects transactionally; never report success before resolution. */
    public function resolveCancellation(GameSession $session, string $reason, string $commandId): array;
}

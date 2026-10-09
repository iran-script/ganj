<?php

namespace App\Services\Games;

use App\Models\GameSession;

interface RuntimeBootstrap
{
    /** Must use the caller database transaction and stable command ID. No irreversible network side effects. */
    public function bootstrap(GameSession $session, array $frozenRosters, string $commandId): array;
}

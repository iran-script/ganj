<?php

namespace App\Services\Games;

use App\Models\GameSession;

interface ScenarioAssignmentReadiness
{
    /** Return validated pinned version references keyed by Team public ID; throw when incomplete. */
    public function assignments(GameSession $session, array $roster): array;
}

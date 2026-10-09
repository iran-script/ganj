<?php

namespace App\Services\Runtime;

use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\TeamNodeInstance;

class UnavailableRuntimeDomains implements ARVerification, HintAction, LiveChallengeTrigger, LocationVerification, QRVerification, ScoreAction
{
    public function execute(TeamNodeInstance $node, ?GameSessionPlayer $player, array $evidence, string $commandId): array
    {
        throw new ApiException('ACTION_NOT_ALLOWED', 'This gameplay domain is not available.', [], 409);
    }
}

<?php

namespace App\Services\Runtime;

use App\Models\GameSessionPlayer;
use App\Models\TeamNodeInstance;

interface ARVerification
{
    /** Trusted result only. Use the caller transaction/outbox and stable commandId; no irreversible network effects here.
     * @return array{accepted:bool,complete:bool}
     */
    public function execute(TeamNodeInstance $node, ?GameSessionPlayer $player, array $evidence, string $commandId): array;
}

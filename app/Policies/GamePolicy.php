<?php

namespace App\Policies;

use App\Models\Game;
use App\Models\User;
use App\Services\Games\GameScope;

class GamePolicy
{
    public function __construct(private GameScope $scope) {}

    public function manage(User $actor, Game $game): bool
    {
        return $this->scope->administrator($actor, $game->organization_id);
    }
}

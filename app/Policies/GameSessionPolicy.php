<?php

namespace App\Policies;

use App\Models\Game;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Games\GameScope;
use App\Services\Organizations\OrganizationScope;

class GameSessionPolicy
{
    public function __construct(private GameScope $scope, private OrganizationScope $organizations) {}

    public function create(User $actor, Game $game): bool
    {
        return $this->organizations->membership($actor, $game->organization)?->role === 'ORGANIZATION_ADMIN';
    }

    public function operate(User $actor, GameSession $session, string $action = 'operate'): bool
    {
        if ($this->organizations->isSuperAdmin($actor)
            && $this->organizations->membership($actor, $session->organization)?->role !== 'ORGANIZATION_ADMIN') {
            return in_array($action, ['start', 'pause', 'resume', 'rebindDevice'], true);
        }

        return $this->scope->operator($actor, $session);
    }

    public function emergency(User $actor, GameSession $session): bool
    {
        return $this->scope->administrator($actor, $session->organization_id);
    }
}

<?php

namespace App\Policies;

use App\Models\Game;
use App\Models\GameRoom;
use App\Models\Team;
use App\Models\User;
use App\Services\Games\GameScope;
use App\Services\Teams\TeamScope;
use Illuminate\Support\Facades\Gate;

class GameRoomPolicy
{
    public function __construct(private GameScope $games, private TeamScope $teams) {}

    public function viewAny(User $actor, Game $game): bool
    {
        return $this->games->games($actor)->whereKey($game->id)->exists()
            && $game->status === 'ACTIVE'
            && $game->organization->status === 'ACTIVE';
    }

    public function view(User $actor, GameRoom $room): bool
    {
        return $room->game !== null && $this->viewAny($actor, $room->game);
    }

    public function create(User $actor, Game $game, Team $team): bool
    {
        return $this->viewAny($actor, $game)
            && $this->teams->teams($actor)->whereKey($team->id)->exists()
            && Gate::forUser($actor)->allows('captain', $team)
            && ($team->organization_id === null || $team->organization_id === $game->organization_id);
    }

    public function addTeam(User $actor, GameRoom $room, Team $team): bool
    {
        return $this->view($actor, $room)
            && $room->status === 'OPEN'
            && $this->teams->teams($actor)->whereKey($team->id)->exists()
            && Gate::forUser($actor)->allows('captain', $team)
            && ($team->organization_id === null || $team->organization_id === $room->game->organization_id);
    }
}

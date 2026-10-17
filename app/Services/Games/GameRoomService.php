<?php

namespace App\Services\Games;

use App\Exceptions\ApiException;
use App\Models\Game;
use App\Models\GameRoom;
use App\Models\GameRoomTeam;
use App\Models\GameSession;
use App\Models\Team;
use App\Models\User;
use App\Services\Idempotency\IdempotencyStore;
use App\Services\Teams\TeamScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GameRoomService
{
    public function __construct(private GameScope $games, private TeamScope $teams, private IdempotencyStore $idempotency) {}

    public function listing(User $actor, string $gameId, array $input): CursorPaginator
    {
        $game = $this->resolveGame($actor, $gameId);
        Gate::forUser($actor)->authorize('viewAny', [GameRoom::class, $game]);

        return GameRoom::query()->where('game_id', $game->id)->with(['game.organization', 'teams.team.captain', 'teams.team.activeMembers'])
            ->orderBy('public_id')->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);
    }

    public function show(User $actor, string $roomId): array
    {
        $room = $this->resolveRoom($actor, $roomId);
        Gate::forUser($actor)->authorize('view', $room);

        return $this->profile($room, $actor);
    }

    public function create(User $actor, string $gameId, array $input, ?string $key): array
    {
        return DB::transaction(function () use ($actor, $gameId, $input, $key): array {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $game = $this->resolveGame($actor, $gameId, true);
            $team = $this->resolveCaptainTeam($actor, $input['team_id']);
            Gate::forUser($actor)->authorize('create', [GameRoom::class, $game, $team]);
            $this->ensureEligible($team, $game);
            $idempotency = $this->reserve($actor, 'create', $gameId, $input, $key);
            if ($idempotency['replay'] !== null) {
                return $idempotency['replay'];
            }
            $room = new GameRoom;
            $room->forceFill(['game_id' => $game->id, 'name' => $input['name'], 'status' => 'OPEN', 'team_capacity' => 5, 'created_by' => $actor->id])->save();
            $this->attachTeam($room, $team, $actor);
            $result = $this->profile($room->fresh(), $actor);
            $this->idempotency->complete($idempotency['key'], $idempotency['fingerprint'], 201, ['data' => $result]);

            return $result;
        }, 3);
    }

    public function addTeam(User $actor, string $roomId, array $input, ?string $key): array
    {
        return DB::transaction(function () use ($actor, $roomId, $input, $key): array {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $room = $this->resolveRoom($actor, $roomId);
            $this->resolveGame($actor, $room->game->public_id, true);
            $room = GameRoom::query()->whereKey($room->id)->lockForUpdate()->firstOrFail()
                ->load(['game.organization', 'teams.team.captain', 'teams.team.activeMembers.user']);
            $team = $this->resolveCaptainTeam($actor, $input['team_id']);
            Gate::forUser($actor)->authorize('addTeam', [$room, $team]);
            $this->ensureEligible($team, $room->game);
            $idempotency = $this->reserve($actor, 'addTeam', $roomId, $input, $key);
            if ($idempotency['replay'] !== null) {
                return $idempotency['replay'];
            }
            $this->attachTeam($room, $team, $actor);
            $result = $this->profile($room->fresh(), $actor);
            $this->idempotency->complete($idempotency['key'], $idempotency['fingerprint'], 200, ['data' => $result]);

            return $result;
        }, 3);
    }

    private function attachTeam(GameRoom $room, Team $team, User $actor): void
    {
        if ($room->status !== 'OPEN' || in_array($this->sessionStatus($room), ['READY', 'RUNNING', 'PAUSED', 'FINISHED', 'CANCELLED'], true)) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'This room is not accepting teams.', [], 409);
        }
        if ($room->teams()->where('team_id', $team->id)->exists()) {
            throw new ApiException('CONFLICT', 'This team is already in the room.', [], 409);
        }
        if ($room->teams()->count() >= $room->team_capacity) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'This room has reached its team capacity.', [], 409);
        }
        $alreadyInRoom = GameRoomTeam::query()->where('team_id', $team->id)
            ->whereHas('room', fn (Builder $query): Builder => $query->where('game_id', $room->game_id)->where('status', 'OPEN'))
            ->exists();
        if ($alreadyInRoom) {
            throw new ApiException('CONFLICT', 'This team is already in an open room for this game.', [], 409);
        }
        $membership = new GameRoomTeam;
        $membership->forceFill(['game_room_id' => $room->id, 'team_id' => $team->id, 'added_by' => $actor->id, 'joined_at' => now()])->save();
    }

    private function ensureEligible(Team $team, Game $game): void
    {
        if ($team->status !== 'ACTIVE' || ($team->organization_id !== null && $team->organization_id !== $game->organization_id)
            || $team->activeMembers()->count() < 4 || $team->activeMembers()->count() > 8) {
            throw new ApiException('TEAM_NOT_ELIGIBLE', 'Team does not meet the game room requirements.', [], 409);
        }
    }

    private function resolveCaptainTeam(User $actor, string $teamId): Team
    {
        $team = $this->teams->resolve($actor, $teamId);
        Gate::forUser($actor)->authorize('captain', $team);

        return Team::query()->with(['organization', 'captain', 'activeMembers.user'])->whereKey($team->id)->lockForUpdate()->firstOrFail();
    }

    private function resolveGame(User $actor, string $gameId, bool $lock = false): Game
    {
        /** @var Game $game */
        $game = $this->games->resolve($actor, $gameId, lock: $lock);
        if ($game->status !== 'ACTIVE' || $game->organization->status !== 'ACTIVE') {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Game not found.', [], 404);
        }

        return $game->load('organization');
    }

    private function resolveRoom(User $actor, string $roomId, bool $lock = false): GameRoom
    {
        $room = Str::isUuid($roomId) ? GameRoom::query()->with(['game.organization', 'teams.team.captain', 'teams.team.activeMembers.user'])
            ->where('public_id', $roomId)->first() : null;
        if (! $room) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Room not found.', [], 404);
        }
        $this->resolveGame($actor, $room->game->public_id);
        Gate::forUser($actor)->authorize('view', $room);
        if ($lock) {
            $room = GameRoom::query()->whereKey($room->id)->lockForUpdate()->firstOrFail()->load(['game.organization', 'teams.team.captain', 'teams.team.activeMembers.user']);
        }

        return $room;
    }

    private function reserve(User $actor, string $action, string $resourceId, array $input, ?string $key): array
    {
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'min:8', 'max:255']])->validate();
        $canonical = $input;
        ksort($canonical);
        $fingerprint = hash('sha256', json_encode([$action, $resourceId, $canonical], JSON_THROW_ON_ERROR));
        $storeKey = 'game-rooms:'.$actor->public_id.':'.hash('sha256', $key);
        $reservation = $this->idempotency->reserve($storeKey, $fingerprint);
        if ($reservation['status'] === 'completed') {
            return ['key' => $storeKey, 'fingerprint' => $fingerprint, 'replay' => $reservation['response']['data']];
        }
        if ($reservation['status'] !== 'reserved') {
            throw new ApiException('CONFLICT', 'The request is still processing.', [], 409);
        }

        return ['key' => $storeKey, 'fingerprint' => $fingerprint, 'replay' => null];
    }

    private function profile(GameRoom $room, User $actor): array
    {
        $memberships = $room->teams->map(function (GameRoomTeam $membership) use ($actor): array {
            $team = $membership->team;
            $count = $team->activeMembers->count();

            return ['id' => $team->public_id, 'name' => $team->name, 'captain' => $team->captain->name,
                'member_count' => $count, 'eligible' => $team->status === 'ACTIVE' && $count >= 4 && $count <= 8,
                'actor_is_member' => $team->activeMembers->contains('user_id', $actor->id),
                'can_add_to_room' => $team->captain_user_id === $actor->id && $team->status === 'ACTIVE'];
        })->values();
        $sessionStatus = $this->sessionStatus($room);
        $status = $room->status;
        if ($status === 'OPEN' && $memberships->count() >= $room->team_capacity) {
            $status = 'FULL';
        }

        return ['id' => $room->public_id, 'game_id' => $room->game->public_id, 'game_name' => $room->game->name,
            'city' => $room->game->city, 'name' => $room->name, 'status' => $status,
            'session_status' => $sessionStatus,
            'capacity' => $room->team_capacity, 'team_count' => $memberships->count(), 'teams' => $memberships->all(),
            'created_at' => $room->created_at?->toISOString()];
    }

    private function sessionStatus(GameRoom $room): ?string
    {
        $teamIds = $room->teams->pluck('team_id')->values();
        if ($teamIds->isEmpty()) {
            return null;
        }
        $sessions = GameSession::query()->where('game_id', $room->game_id)
            ->whereIn('status', ['REGISTRATION', 'READY', 'RUNNING', 'PAUSED', 'FINISHED', 'CANCELLED'])
            ->whereHas('teams', fn (Builder $query): Builder => $query->whereIn('team_id', $teamIds))
            ->orderByDesc('created_at')->get();
        foreach ($sessions as $session) {
            if ($session->teams()->whereIn('team_id', $teamIds)->count() === $teamIds->count()) {
                return $session->status;
            }
        }

        return null;
    }
}

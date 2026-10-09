<?php

namespace App\Services\Games;

use App\Exceptions\ApiException;
use App\Models\GameSession;
use App\Models\GameSessionPlayer;
use App\Models\GameSessionTeam;
use App\Models\Team;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SessionRoster
{
    public function __construct(private GameEvents $events, private GameScope $scope) {}

    public function team(User $actor, GameSession $session, string $id, array $input): Team
    {
        $team = Str::isUuid($id) ? Team::where('public_id', $id)->lockForUpdate()->first() : null;
        if (! $team || ($team->organization_id !== null && $team->organization_id !== $session->organization_id)) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Team not found.', [], 404);
        }
        if (! Gate::forUser($actor)->allows('captain', $team)) {
            Gate::forUser($actor)->authorize('operate', $session);
            GameInput::reason($input);
        }
        if (($session->registration_opens_at && now()->lt($session->registration_opens_at)) || ($session->registration_closes_at && now()->gte($session->registration_closes_at))) {
            Gate::forUser($actor)->authorize('operate', $session);
            GameInput::reason($input);
        }

        return $team;
    }

    public function register(User $actor, GameSession $session, array $input): void
    {
        if ($session->status !== 'REGISTRATION') {
            throw new ApiException('SESSION_NOT_OPEN', 'Registration is not open.', [], 409);
        }
        $team = $this->team($actor, $session, $input['team_id'], $input);
        if ($session->teams()->where('team_id', $team->id)->exists()) {
            throw new ApiException('CONFLICT', 'Team already has a registration in this Session.', [], 409);
        }
        if ($session->teams()->whereNotIn('status', ['REMOVED', 'DISQUALIFIED'])->count() >= 5) {
            throw new ApiException('SESSION_TEAM_CAPACITY_REACHED', 'The Session already has five Teams.', [], 409);
        }
        $members = $team->activeMembers()->orderBy('user_id')->get();
        if ($team->status !== 'ACTIVE' || $members->count() < 4 || $members->count() > 8) {
            throw new ApiException('TEAM_NOT_ELIGIBLE', 'Team must have four to eight active members.', [], 409);
        }
        $registration = new GameSessionTeam;
        $registration->forceFill(['game_session_id' => $session->id, 'team_id' => $team->id, 'status' => 'REGISTERED', 'registered_at' => now()])->save();
        $this->events->record('game_session.team_registered.v1', $actor, $session, ['team_id' => $team->public_id], $input['reason'] ?? null);
        foreach ($members as $member) {
            $this->addPlayer($actor, $session, $registration, $member->user);
        }
    }

    public function addPlayer(User $actor, GameSession $session, GameSessionTeam $registration, User $user): void
    {
        $user->fresh()->ensureActive();
        if ($session->players()->where('user_id', $user->id)->exists()) {
            throw new ApiException('CONFLICT', 'A User may have only one roster identity per Session.', [], 409);
        }
        $player = new GameSessionPlayer;
        $player->forceFill(['game_session_id' => $session->id, 'game_session_team_id' => $registration->id, 'user_id' => $user->id, 'participation_status' => 'JOINED'])->save();
        $this->playerEvent('game_session.player_added_to_roster.v1', $actor, $session, $player);
        $this->playerEvent('player_session.joined.v1', $actor, $session, $player);
    }

    public function remove(User $actor, GameSession $session, string $teamId, array $input): void
    {
        $team = $this->team($actor, $session, $teamId, $input);
        $registration = $session->teams()->where('team_id', $team->id)->first();
        if (! $registration) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Registration not found.', [], 404);
        }
        if ($registration->status === 'REMOVED') {
            return;
        }
        if (! in_array($session->status, ['REGISTRATION', 'READY'], true) || ! in_array($registration->status, ['REGISTERED', 'READY'], true)) {
            throw new ApiException('STATE_TRANSITION_INVALID', 'Only pre-start registrations may be removed.', [], 409);
        }
        foreach ($registration->players()->whereNotIn('participation_status', ['REMOVED', 'DISQUALIFIED'])->get() as $player) {
            $this->removePlayer($actor, $session, $player, $input['reason'] ?? null);
        }
        $registration->forceFill(['status' => 'REMOVED', 'removed_at' => now()])->save();
        foreach (['game_session.team_removed.v1', 'session_team.removed.v1'] as $type) {
            $this->events->record($type, $actor, $session, ['team_id' => $team->public_id], $input['reason'] ?? null);
        }
    }

    public function removePlayer(User $actor, GameSession $session, GameSessionPlayer $player, ?string $reason = null): void
    {
        $player->forceFill(['participation_status' => 'REMOVED'])->save();
        foreach (['game_session.player_removed_from_roster.v1', 'player_session.removed.v1'] as $type) {
            $this->playerEvent($type, $actor, $session, $player, [], $reason);
        }
    }

    /** Called only under the Session lock. Locks account/device rows through commit so revocation cannot race Start. */
    public function context(GameSession $session, bool $requireReady = false): array
    {
        $registrations = $session->teams()->whereNotIn('status', ['REMOVED', 'DISQUALIFIED'])->orderBy('id')->get();
        if ($registrations->count() < 1 || $registrations->count() > 5) {
            throw new ApiException('SESSION_NOT_READY', 'One to five Teams are required.', [], 409);
        }
        $result = [];
        foreach ($registrations as $registration) {
            $players = $registration->players()->whereNotIn('participation_status', ['REMOVED', 'DISQUALIFIED'])->orderBy('user_id')->get();
            if ($players->count() < 4 || $players->count() > 8) {
                throw new ApiException('TEAM_NOT_ELIGIBLE', 'Every roster must contain four to eight Players.', [], 409);
            }
            $ready = 0;
            $data = [];
            foreach ($players as $player) {
                $user = User::whereKey($player->user_id)->lockForUpdate()->firstOrFail();
                if ($user->status !== 'ACTIVE') {
                    throw new ApiException('SESSION_NOT_READY', 'A roster account is not active.', [], 409);
                }
                $device = $player->active_device_id ? UserDevice::whereKey($player->active_device_id)->lockForUpdate()->first() : null;
                $valid = $device && $device->user_id === $player->user_id && $device->revoked_at === null;
                if ($requireReady && $player->participation_status === 'READY' && ! $valid) {
                    throw new ApiException('SESSION_NOT_READY', 'A READY Player no longer has a valid Device.', [], 409);
                }
                if ($player->participation_status === 'READY' && $valid) {
                    $ready++;
                }
                $data[] = ['player_id' => $player->public_id, 'user_id' => $user->public_id, 'device_id' => $valid ? $device->public_id : null,
                    'ready' => $player->participation_status === 'READY', 'joined_at' => $player->created_at->toISOString()];
            }
            if ($requireReady && $ready < 4) {
                throw new ApiException('SESSION_NOT_READY', 'Each Team needs four READY Players with valid bound Devices.', [], 409);
            }
            $result[] = ['team_id' => $registration->team->public_id, 'players' => $data, 'eligible' => true, 'ready_count' => $ready];
        }

        return $result;
    }

    public function refreshReadiness(User $actor, GameSession $session, GameSessionTeam $registration): void
    {
        $players = $registration->players()->whereNotIn('participation_status', ['REMOVED', 'DISQUALIFIED'])->get();
        $ready = $players->filter(fn (GameSessionPlayer $p): bool => $p->participation_status === 'READY' && $p->user->status === 'ACTIVE' && $p->device !== null && $p->device->revoked_at === null)->count();
        $status = $players->count() >= 4 && $players->count() <= 8 && $ready >= 4 ? 'READY' : 'REGISTERED';
        if ($registration->status !== $status) {
            $registration->forceFill(['status' => $status])->save();
            if ($status === 'READY') {
                $this->events->record('session_team.ready.v1', $actor, $session, ['team_id' => $registration->team->public_id]);
            }
        }
    }

    public function playerEvent(string $type, User $actor, GameSession $session, GameSessionPlayer $player, array $data = [], ?string $reason = null): void
    {
        $this->events->record($type, $actor, $session, $data + ['team_id' => $player->registration->team->public_id, 'player_id' => $player->public_id, 'user_id' => $player->user->public_id], $reason);
    }
}

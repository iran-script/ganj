<?php

namespace App\Services\Games;

use App\Models\Game;
use App\Models\GameSession;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Organizations\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GameScope
{
    public function __construct(private OrganizationScope $organizations) {}

    public function administrator(User $actor, int $organizationId): bool
    {
        return $this->organizations->organizations($actor, true)->whereKey($organizationId)->exists();
    }

    public function operator(User $actor, Game|GameSession $resource): bool
    {
        if ($this->administrator($actor, $resource->organization_id)) {
            return true;
        }
        if (! $this->organizations->organizations($actor)->whereKey($resource->organization_id)->exists()) {
            return false;
        }

        return RoleAssignment::query()->where('user_id', $actor->id)->where('organization_id', $resource->organization_id)
            ->where('role_code', 'GAMEMASTER')->whereNull('revoked_at')
            ->where(function (Builder $query) use ($resource): void {
                $query->where(fn (Builder $q): Builder => $q->where('resource_type', 'game')->where('resource_id', $resource instanceof Game ? $resource->public_id : $resource->game->public_id));
                if ($resource instanceof GameSession) {
                    $query->orWhere(fn (Builder $q): Builder => $q->where('resource_type', 'game_session')->where('resource_id', $resource->public_id));
                }
            })->exists();
    }

    public function games(User $actor): Builder
    {
        return Game::query()->withGlobalScope('game_access', function (Builder $query) use ($actor): void {
            $actor->fresh()->ensureActive();
            $query->where(function (Builder $q) use ($actor): void {
                $q->whereIn('organization_id', $this->organizations->organizations($actor, true)->select('organizations.id'))
                    ->orWhere(function (Builder $assigned) use ($actor): void {
                        $assigned->whereIn('organization_id', $this->organizations->organizations($actor)->select('organizations.id'))
                            ->whereIn(DB::raw('public_id::text'), RoleAssignment::query()->select('resource_id')->where('user_id', $actor->id)->where('resource_type', 'game')->where('role_code', 'GAMEMASTER')->whereNull('revoked_at'));
                    });
                if (! OrganizationMember::where('user_id', $actor->id)->where('status', 'ACTIVE')->exists()) {
                    $q->orWhere(fn (Builder $public): Builder => $public->where('status', 'ACTIVE')->whereHas('organization', fn (Builder $org): Builder => $org->where('status', 'ACTIVE')));
                }
            });
        });
    }

    public function sessions(User $actor): Builder
    {
        return GameSession::query()->withGlobalScope('session_access', function (Builder $query) use ($actor): void {
            $actor->fresh()->ensureActive();
            $query->where(function (Builder $q) use ($actor): void {
                $q->whereIn('organization_id', $this->organizations->organizations($actor, true)->select('organizations.id'))
                    ->orWhere(function (Builder $assigned) use ($actor): void {
                        $roles = RoleAssignment::query()->where('user_id', $actor->id)->where('role_code', 'GAMEMASTER')->whereNull('revoked_at');
                        $assigned->whereIn('organization_id', $this->organizations->organizations($actor)->select('organizations.id'))
                            ->where(fn (Builder $resources): Builder => $resources->whereIn(DB::raw('public_id::text'), (clone $roles)->where('resource_type', 'game_session')->select('resource_id'))
                                ->orWhereHas('game', fn (Builder $game): Builder => $game->whereIn(DB::raw('public_id::text'), (clone $roles)->where('resource_type', 'game')->select('resource_id'))));
                    });
                if (! OrganizationMember::where('user_id', $actor->id)->where('status', 'ACTIVE')->exists()) {
                    $q->orWhereHas('players', fn (Builder $p): Builder => $p->where('user_id', $actor->id))
                        ->orWhere(fn (Builder $available): Builder => $available->where('status', 'REGISTRATION')->whereHas('game', fn (Builder $g): Builder => $g->where('status', 'ACTIVE'))->whereHas('organization', fn (Builder $o): Builder => $o->where('status', 'ACTIVE')));
                } else {
                    $q->orWhere(fn (Builder $own): Builder => $own->whereIn('organization_id', $this->organizations->organizations($actor)->select('organizations.id'))->whereHas('players', fn (Builder $p): Builder => $p->where('user_id', $actor->id)));
                }
            });
        });
    }

    public function resolve(User $actor, string $id, bool $session = false, bool $lock = false): Game|GameSession
    {
        if (! Str::isUuid($id)) {
            throw $this->organizations->notFound();
        }
        $resource = ($session ? $this->sessions($actor) : $this->games($actor))->where('public_id', $id)->first();
        if (! $resource) {
            throw $this->organizations->notFound();
        }
        if ($lock) {
            Organization::whereKey($resource->organization_id)->lockForUpdate()->firstOrFail();
            if ($session) {
                Game::whereKey($resource->game_id)->lockForUpdate()->firstOrFail();
            }
            $resource = ($session ? $this->sessions($actor) : $this->games($actor))->whereKey($resource->id)->lockForUpdate()->first();
            if (! $resource) {
                throw $this->organizations->notFound();
            }
        }

        return $resource;
    }
}

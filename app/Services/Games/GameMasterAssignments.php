<?php

namespace App\Services\Games;

use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Organizations\OrganizationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GameMasterAssignments
{
    public function __construct(private GameScope $scope, private OrganizationScope $organizations, private AuditService $audit) {}

    public function assign(User $actor, User $target, string $resourceId, bool $session, string $reason): RoleAssignment
    {
        return DB::transaction(function () use ($actor, $target, $resourceId, $session, $reason): RoleAssignment {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $resource = $this->scope->resolve($actor, $resourceId, $session, true);
            abort_unless($this->scope->administrator($actor, $resource->organization_id), 403);
            GameInput::reason(['reason' => $reason]);
            $target->fresh()->ensureActive();
            $membership = $this->organizations->membership($target, $resource->organization);
            if ($membership?->role !== 'GAMEMASTER') {
                throw ValidationException::withMessages(['user_id' => 'An active GameMaster membership in this organization is required.']);
            }
            $values = ['user_id' => $target->id, 'organization_id' => $resource->organization_id, 'resource_type' => $session ? 'game_session' : 'game', 'resource_id' => $resource->public_id, 'role_code' => 'GAMEMASTER'];
            $existing = RoleAssignment::where($values)->whereNull('revoked_at')->first();
            if ($existing) {
                return $existing;
            }
            $assignment = new RoleAssignment;
            $assignment->forceFill($values + ['created_at' => now()])->save();
            $this->audit->record('role_assignment.granted', $session ? 'game_session' : 'game', $resource->public_id, $reason, ['user_id' => $target->public_id, 'role_code' => 'GAMEMASTER'], $actor->id, $resource->organization_id);

            return $assignment;
        }, 3);
    }

    public function revoke(User $actor, User $target, string $resourceId, bool $session, string $reason): void
    {
        DB::transaction(function () use ($actor, $target, $resourceId, $session, $reason): void {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor->ensureActive();
            $resource = $this->scope->resolve($actor, $resourceId, $session, true);
            abort_unless($this->scope->administrator($actor, $resource->organization_id), 403);
            GameInput::reason(['reason' => $reason]);
            $count = RoleAssignment::where('user_id', $target->id)->where('organization_id', $resource->organization_id)->where('resource_type', $session ? 'game_session' : 'game')->where('resource_id', $resource->public_id)->where('role_code', 'GAMEMASTER')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            if ($count) {
                $this->audit->record('role_assignment.revoked', $session ? 'game_session' : 'game', $resource->public_id, $reason, ['user_id' => $target->public_id, 'role_code' => 'GAMEMASTER'], $actor->id, $resource->organization_id);
            }
        }, 3);
    }
}

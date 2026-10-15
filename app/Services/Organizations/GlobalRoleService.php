<?php

namespace App\Services\Organizations;

use App\Exceptions\ApiException;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class GlobalRoleService
{
    public function __construct(private AuditService $audit, private OrganizationScope $scope) {}

    public function bootstrap(string $userId, string $reason): RoleAssignment
    {
        if (! app()->runningInConsole()) {
            throw new ApiException('AUTHORIZATION_DENIED', 'Bootstrap is available only to the local operator.', [], 403);
        }

        return DB::transaction(function () use ($userId, $reason): RoleAssignment {
            DB::statement('LOCK TABLE role_assignments IN SHARE ROW EXCLUSIVE MODE');
            if (RoleAssignment::query()->where('role_code', RoleAssignment::SUPER_ADMIN)->exists()) {
                throw new ApiException('CONFLICT', 'The global role baseline has already been initialized.', [], 409);
            }
            OrganizationInput::validate(['reason' => $reason], 'archive');
            $target = $this->target($userId);
            $target->ensureActive();

            return $this->recordGrant(null, $target, $reason);
        }, 3);
    }

    public function grant(User $actor, string $userId, string $reason): RoleAssignment
    {
        return DB::transaction(function () use ($actor, $userId, $reason): RoleAssignment {
            DB::statement('LOCK TABLE role_assignments IN SHARE ROW EXCLUSIVE MODE');
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $lockedActor->ensureActive();
            Gate::forUser($lockedActor)->authorize('roles.manageGlobal');
            OrganizationInput::validate(['reason' => $reason], 'archive');
            $target = $this->target($userId);
            $target->ensureActive();
            $existing = $this->activeAssignment($target);
            if ($existing) {
                return $existing;
            }

            return $this->recordGrant($actor, $target, $reason);
        }, 3);
    }

    public function revoke(User $actor, string $userId, string $reason): void
    {
        DB::transaction(function () use ($actor, $userId, $reason): void {
            DB::statement('LOCK TABLE role_assignments IN SHARE ROW EXCLUSIVE MODE');
            $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $lockedActor->ensureActive();
            Gate::forUser($lockedActor)->authorize('roles.manageGlobal');
            OrganizationInput::validate(['reason' => $reason], 'archive');
            $target = $this->target($userId);
            $assignment = $this->activeAssignment($target);
            if (! $assignment) {
                throw $this->scope->notFound();
            }
            if ($target->status === 'ACTIVE' && $this->activeSuperAdminCount() <= 1) {
                throw new ApiException('ACTION_NOT_ALLOWED', 'The last active SUPER_ADMIN assignment cannot be revoked.', [], 409);
            }
            $assignment->forceFill(['revoked_at' => now()])->save();
            $this->audit->record('role_assignment.revoked', 'user', $target->public_id, $reason, ['role_code' => RoleAssignment::SUPER_ADMIN, 'scope' => 'GLOBAL'], $lockedActor->id);
        }, 3);
    }

    private function target(string $userId): User
    {
        if (! Str::isUuid($userId)) {
            throw $this->scope->notFound();
        }

        return User::query()->where('public_id', $userId)->lockForUpdate()->first() ?? throw $this->scope->notFound();
    }

    private function activeAssignment(User $user): ?RoleAssignment
    {
        return RoleAssignment::query()->where('user_id', $user->id)->where('role_code', RoleAssignment::SUPER_ADMIN)
            ->whereNull('organization_id')->whereNull('resource_type')->whereNull('resource_id')->whereNull('revoked_at')->first();
    }

    private function activeSuperAdminCount(): int
    {
        return RoleAssignment::query()->where('role_code', RoleAssignment::SUPER_ADMIN)->whereNull('organization_id')
            ->whereNull('resource_type')->whereNull('resource_id')->whereNull('revoked_at')
            ->whereHas('user', fn ($query) => $query->where('status', 'ACTIVE'))->count();
    }

    private function recordGrant(?User $actor, User $target, string $reason): RoleAssignment
    {
        $assignment = new RoleAssignment;
        $assignment->forceFill(['user_id' => $target->id, 'role_code' => RoleAssignment::SUPER_ADMIN, 'created_at' => now()])->save();
        $this->audit->record('role_assignment.granted', 'user', $target->public_id, $reason, ['role_code' => RoleAssignment::SUPER_ADMIN, 'scope' => 'GLOBAL', 'bootstrap' => $actor === null], $actor?->id);

        return $assignment;
    }
}

<?php

namespace App\Services\Organizations;

use App\Exceptions\ApiException;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OperatorService
{
    public function __construct(private AuditService $audit) {}

    public function grant(string $mobile, string $reason): RoleAssignment
    {
        if (! app()->runningInConsole()) {
            throw new ApiException('AUTHORIZATION_DENIED', 'Operator grants require local CLI access.', [], 403);
        }
        Validator::make(['mobile' => $mobile, 'reason' => $reason], [
            'mobile' => ['required', 'regex:/^\+?[0-9]{7,15}$/'], 'reason' => 'required|string|max:1000',
        ])->validate();
        OrganizationInput::validate(['reason' => $reason], 'archive');

        return DB::transaction(function () use ($mobile, $reason): RoleAssignment {
            $user = User::where('mobile', $mobile)->lockForUpdate()->first();
            if (! $user) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'An existing user is required.', [], 404);
            }
            $user->ensureActive();
            $existing = RoleAssignment::where('user_id', $user->id)->where('role_code', 'ORGANIZATION_OPERATOR')->whereNull('revoked_at')->first();
            if ($existing) {
                return $existing;
            }
            $assignment = new RoleAssignment;
            $assignment->forceFill(['user_id' => $user->id, 'role_code' => 'ORGANIZATION_OPERATOR', 'created_at' => now()])->save();
            $this->audit->record('role_assignment.granted', 'user', $user->public_id, $reason,
                ['role_code' => 'ORGANIZATION_OPERATOR', 'capability' => 'organization.create', 'source' => 'CLI']);

            return $assignment;
        }, 3);
    }
}

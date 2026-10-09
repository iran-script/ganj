<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationSetting;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public static function constraints(): array
    {
        return [
            'unique slug' => ['slug', '23505'],
            'unique active membership' => ['membership', '23505'],
            'member organization fk' => ['member_org_fk', '23503'],
            'member user fk' => ['member_user_fk', '23503'],
            'unique settings' => ['settings', '23505'],
            'settings organization fk' => ['settings_fk', '23503'],
            'role user fk' => ['role_user_fk', '23503'],
            'tenant super admin rejected' => ['role_tenant', '23514'],
            'resource super admin rejected' => ['role_resource', '23514'],
            'partial resource scope rejected' => ['role_partial', '23514'],
            'duplicate global grant rejected' => ['role_duplicate', '23505'],
            'second tenant role store rejected' => ['role_tenant_code', '23514'],
            'membership role allowlist' => ['member_role', '23514'],
            'membership status allowlist' => ['member_status', '23514'],
            'closed period requires timestamp' => ['member_left', '23514'],
            'active period cannot be closed' => ['member_active_left', '23514'],
            'invalid period' => ['member_period', '23514'],
            'archive requires timestamp' => ['archive', '23514'],
            'organization fk protects history' => ['delete_org', '23503'],
            'user fk protects history' => ['delete_user', '23503'],
        ];
    }

    #[DataProvider('constraints')]
    public function test_postgresql_rejects_invalid_tenant_data(string $constraint, string $sqlState): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $member = OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
        $settings = OrganizationSetting::factory()->create(['organization_id' => $organization->id]);
        $assignment = RoleAssignment::factory()->create(['user_id' => $user->id]);

        try {
            DB::transaction(function () use ($constraint, $organization, $user, $member, $settings, $assignment): void {
                match ($constraint) {
                    'slug' => Organization::factory()->create(['slug' => $organization->slug]),
                    'membership' => OrganizationMember::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]),
                    'member_org_fk' => DB::table('organization_members')->where('id', $member->id)->update(['organization_id' => 99999999]),
                    'member_user_fk' => DB::table('organization_members')->where('id', $member->id)->update(['user_id' => 99999999]),
                    'settings' => OrganizationSetting::factory()->create(['organization_id' => $organization->id]),
                    'settings_fk' => DB::table('organization_settings')->where('id', $settings->id)->update(['organization_id' => 99999999]),
                    'role_user_fk' => DB::table('role_assignments')->where('id', $assignment->id)->update(['user_id' => 99999999]),
                    'role_tenant' => DB::table('role_assignments')->where('id', $assignment->id)->update(['organization_id' => $organization->id]),
                    'role_resource' => DB::table('role_assignments')->where('id', $assignment->id)->update(['resource_type' => 'organization', 'resource_id' => $organization->public_id]),
                    'role_partial' => DB::table('role_assignments')->where('id', $assignment->id)->update(['resource_type' => 'organization']),
                    'role_duplicate' => RoleAssignment::factory()->create(['user_id' => $user->id]),
                    'role_tenant_code' => DB::table('role_assignments')->where('id', $assignment->id)->update(['role_code' => 'ORGANIZATION_ADMIN']),
                    'member_role' => DB::table('organization_members')->where('id', $member->id)->update(['role' => 'SUPER_ADMIN']),
                    'member_status' => DB::table('organization_members')->where('id', $member->id)->update(['status' => 'OTHER']),
                    'member_left' => DB::table('organization_members')->where('id', $member->id)->update(['status' => 'REMOVED']),
                    'member_active_left' => DB::table('organization_members')->where('id', $member->id)->update(['left_at' => now()]),
                    'member_period' => DB::table('organization_members')->where('id', $member->id)->update(['status' => 'LEFT', 'left_at' => now()->subDay()]),
                    'archive' => DB::table('organizations')->where('id', $organization->id)->update(['status' => 'ARCHIVED']),
                    'delete_org' => DB::table('organizations')->where('id', $organization->id)->delete(),
                    'delete_user' => DB::table('users')->where('id', $user->id)->delete(),
                };
            });
            $this->fail('Invalid tenant data must be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
        }
    }

    public function test_removed_memberships_and_revoked_roles_allow_new_history_periods(): void
    {
        $old = OrganizationMember::factory()->removed()->create();
        OrganizationMember::factory()->create(['organization_id' => $old->organization_id, 'user_id' => $old->user_id]);
        RoleAssignment::factory()->revoked()->create(['user_id' => $old->user_id]);

        RoleAssignment::factory()->create(['user_id' => $old->user_id]);

        $this->assertDatabaseCount('organization_members', 2);
        $this->assertDatabaseCount('role_assignments', 2);
        $this->assertDatabaseHas('organization_members', ['id' => $old->id, 'status' => 'REMOVED']);
    }

    public static function auditMutations(): array
    {
        return ['update' => ['update'], 'delete' => ['delete']];
    }

    #[DataProvider('auditMutations')]
    public function test_audit_is_append_only_at_database_boundary(string $mutation): void
    {
        $id = app(AuditService::class)->record('test', 'organization', 'public-resource');

        try {
            DB::transaction(function () use ($id, $mutation): void {
                $query = DB::table('audit_events')->where('id', $id);
                if ($mutation === 'update') {
                    $query->update(['action' => 'tampered']);
                } else {
                    $query->delete();
                }
            });
            $this->fail('Audit mutation must fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->assertDatabaseHas('audit_events', ['id' => $id, 'action' => 'test']);
    }
}

<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Management\ManagementService;
use App\Services\Organizations\GlobalRoleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManagementConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_status_and_super_admin_revocation_are_serialized(): void
    {
        $firstAdmin = User::factory()->create();
        $secondAdmin = User::factory()->create();
        RoleAssignment::factory()->create(['user_id' => $firstAdmin->id]);
        RoleAssignment::factory()->create(['user_id' => $secondAdmin->id]);

        $default = DB::getDefaultConnection();
        config(['database.connections.management_lock_probe' => config('database.connections.'.$default)]);
        $primary = DB::connection($default);
        $probe = DB::connection('management_lock_probe');
        $probe->statement("SET lock_timeout = '200ms'");
        $primary->beginTransaction();

        try {
            app(ManagementService::class)->setStatus($firstAdmin, $secondAdmin->public_id, [
                'status' => 'SUSPENDED', 'reason' => 'Concurrent lock test',
            ]);

            DB::setDefaultConnection('management_lock_probe');
            try {
                app(GlobalRoleService::class)->revoke($secondAdmin, $firstAdmin->public_id, 'Concurrent revoke');
                $this->fail('The competing role mutation must wait for the shared role-assignment lock.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            }
        } finally {
            DB::setDefaultConnection($default);
            $primary->commit();
            DB::purge('management_lock_probe');
        }

        try {
            app(GlobalRoleService::class)->revoke($firstAdmin, $firstAdmin->public_id, 'Last administrator check');
            $this->fail('The final active SUPER_ADMIN assignment must remain protected.');
        } catch (ApiException $exception) {
            $this->assertSame('ACTION_NOT_ALLOWED', $exception->errorCode);
        }

        $this->assertSame('SUSPENDED', $secondAdmin->fresh()->status);
        $this->assertSame(1, RoleAssignment::query()->where('role_code', RoleAssignment::SUPER_ADMIN)
            ->whereNull('revoked_at')->whereHas('user', fn ($users) => $users->where('status', 'ACTIVE'))->count());
    }
}

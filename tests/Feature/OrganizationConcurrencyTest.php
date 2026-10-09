<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Services\Organizations\OrganizationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_organization_mutations_serialize_on_the_organization_lock(): void
    {
        $organization = Organization::factory()->create();
        $first = OrganizationMember::factory()->administrator()->create(['organization_id' => $organization->id]);
        $second = OrganizationMember::factory()->administrator()->create(['organization_id' => $organization->id]);
        $firstActor = $first->user;
        $secondActor = $second->user;
        $default = DB::getDefaultConnection();
        config(['database.connections.organization_lock_probe' => config('database.connections.'.$default)]);
        $primary = DB::connection($default);
        $probe = DB::connection('organization_lock_probe');
        $probe->statement("SET lock_timeout = '200ms'");
        $primary->beginTransaction();

        try {
            app(OrganizationService::class)->update($firstActor, $organization->public_id, ['name' => 'Uncommitted']);
            DB::setDefaultConnection('organization_lock_probe');
            try {
                app(OrganizationService::class)->update($secondActor, $organization->public_id, ['name' => 'Concurrent']);
                $this->fail('The competing mutation must wait for the organization lock.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            }
        } finally {
            DB::setDefaultConnection($default);
            $primary->rollBack();
            DB::purge('organization_lock_probe');
        }

        $this->assertSame($organization->name, $organization->fresh()->name);
        $this->assertDatabaseCount('outbox_events', 0);
        $result = app(OrganizationService::class)->update($secondActor, $organization->public_id, ['name' => 'After release']);
        $this->assertSame('After release', $result->name);
        $this->assertDatabaseCount('outbox_events', 1);
    }
}

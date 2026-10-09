<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationSetting;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Organizations\GlobalRoleService;
use App\Services\Organizations\OrganizationScope;
use App\Services\Organizations\OrganizationService;
use App\Services\Outbox\OutboxService;
use Database\Seeders\OrganizationSettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationServiceTest extends TestCase
{
    use RefreshDatabase;

    public static function permissions(): array
    {
        return [
            'super admin' => ['SUPER_ADMIN', [true, true, true, true, true, true]],
            'organization admin' => ['ORGANIZATION_ADMIN', [true, false, true, true, true, false]],
            'game master' => ['GAMEMASTER', [false, false, true, false, false, false]],
            'staff' => ['STAFF', [false, false, false, false, false, false]],
            'player' => ['PLAYER', [false, false, false, false, false, false]],
        ];
    }

    #[DataProvider('permissions')]
    public function test_policy_enforces_each_allowed_capability(string $role, array $expected): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        if ($role === 'SUPER_ADMIN') {
            RoleAssignment::factory()->create(['user_id' => $user->id]);
        } elseif ($role !== 'PLAYER') {
            OrganizationMember::factory()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'role' => $role]);
        }

        foreach (['viewAny', 'create', 'view', 'update', 'manageMembers', 'archive'] as $index => $ability) {
            $this->assertSame($expected[$index], Gate::forUser($user)->allows($ability, in_array($ability, ['viewAny', 'create'], true) ? Organization::class : $organization), $role.' '.$ability);
        }
    }

    public function test_service_and_reusable_context_scope_ownership_on_server(): void
    {
        $member = OrganizationMember::factory()->administrator()->create();
        $other = OrganizationMember::factory()->administrator()->create();
        OrganizationSetting::factory()->create(['organization_id' => $member->organization_id]);
        OrganizationSetting::factory()->create(['organization_id' => $other->organization_id]);
        $scope = app(OrganizationScope::class);
        $context = $scope->resolve($member->user, $member->organization->public_id);

        $this->assertSame([$member->organization_id], $scope->organizations($member->user)->orWhereRaw('1 = 1')->pluck('id')->all());
        $this->assertSame([$member->organization_id], $context->scope(OrganizationSetting::query()->where('organization_id', $other->organization_id)->orWhereRaw('1 = 1'))->pluck('organization_id')->all());
        $this->assertSame($member->id, $context->membership->id);
        try {
            app(OrganizationService::class)->update($member->user, $other->organization->public_id, ['name' => 'Stolen']);
            $this->fail('A service caller cannot bypass tenant authorization.');
        } catch (ApiException $exception) {
            $this->assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
        }
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_role_changes_are_explicit_audited_and_immediately_effective(): void
    {
        $admin = OrganizationMember::factory()->administrator()->create();
        $target = OrganizationMember::factory()->gameMaster()->create(['organization_id' => $admin->organization_id]);
        request()->attributes->set('request_id', 'role-change-test');

        app(OrganizationService::class)->changeRole($admin->user, $admin->organization->public_id, $target->user->public_id, ['role' => 'ORGANIZATION_ADMIN', 'reason' => 'Promoted to organization operator']);

        $this->assertTrue(Gate::forUser($target->user)->allows('manageMembers', $admin->organization));
        $this->assertDatabaseHas('organization_members', ['id' => $target->id, 'role' => 'ORGANIZATION_ADMIN']);
        $this->assertDatabaseCount('organization_members', 2);
        $payload = json_decode(DB::table('outbox_events')->sole()->payload, true);
        $this->assertSame('organization.member_role_changed.v1', $payload['event_type']);
        $this->assertSame('GAMEMASTER', $payload['data']['old_role']);
        $this->assertSame('ORGANIZATION_ADMIN', $payload['data']['new_role']);
        $this->assertSame($target->user->public_id, $payload['data']['user_id']);
        $this->assertDatabaseHas('audit_events', ['action' => 'organization.member_role_changed.v1', 'reason' => 'Promoted to organization operator', 'request_id' => 'role-change-test']);
    }

    public function test_role_change_cannot_escape_tenant_or_grant_global_privilege(): void
    {
        $admin = OrganizationMember::factory()->administrator()->create();
        $other = OrganizationMember::factory()->create();
        $service = app(OrganizationService::class);
        try {
            $service->changeRole($admin->user, $admin->organization->public_id, $other->user->public_id, ['role' => 'ORGANIZATION_ADMIN', 'reason' => 'Not permitted']);
            $this->fail('A target in another organization must not resolve.');
        } catch (ApiException $exception) {
            $this->assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
        }
        try {
            $service->changeRole($admin->user, $admin->organization->public_id, $admin->user->public_id, ['role' => 'SUPER_ADMIN', 'reason' => 'Escalation']);
            $this->fail('Tenant role changes cannot grant SuperAdmin.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }
        $this->assertDatabaseCount('role_assignments', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_retained_context_does_not_keep_revoked_tenant_permission(): void
    {
        $admin = OrganizationMember::factory()->administrator()->create();
        $target = OrganizationMember::factory()->administrator()->create(['organization_id' => $admin->organization_id]);
        $context = app(OrganizationScope::class)->resolve($target->user, $admin->organization->public_id);
        $deferredQuery = $context->scope(OrganizationSetting::query());
        app(OrganizationService::class)->removeMember($admin->user, $admin->organization->public_id, $target->user->public_id);

        $this->expectException(AuthorizationException::class);
        $deferredQuery->get();
    }

    public static function inactiveMemberships(): array
    {
        return ['suspended' => ['SUSPENDED', false], 'left' => ['LEFT', true], 'removed' => ['REMOVED', true]];
    }

    #[DataProvider('inactiveMemberships')]
    public function test_inactive_memberships_do_not_authorize(string $status, bool $closed): void
    {
        $member = OrganizationMember::factory()->administrator()->create(['status' => $status, 'left_at' => $closed ? now() : null]);

        $this->assertFalse(Gate::forUser($member->user)->allows('view', $member->organization));
        $this->assertFalse(Gate::forUser($member->user)->allows('manageMembers', $member->organization));
        $this->assertSame(0, app(OrganizationScope::class)->organizations($member->user)->count());
    }

    public function test_suspended_account_cannot_use_stale_actor_or_global_grant(): void
    {
        $actor = User::factory()->create();
        RoleAssignment::factory()->create(['user_id' => $actor->id]);
        DB::table('users')->where('id', $actor->id)->update(['status' => 'SUSPENDED']);

        $this->assertFalse(Gate::forUser($actor)->allows('create', Organization::class));
        $this->expectException(ApiException::class);
        app(OrganizationService::class)->create($actor, ['name' => 'Rejected', 'slug' => 'rejected']);
    }

    public function test_archive_preserves_history_and_blocks_mutation(): void
    {
        $actor = User::factory()->create();
        RoleAssignment::factory()->create(['user_id' => $actor->id]);
        $organization = Organization::factory()->create();
        $member = OrganizationMember::factory()->create(['organization_id' => $organization->id]);
        $this->travelTo(now()->startOfSecond());

        app(OrganizationService::class)->archive($actor, $organization->public_id, 'Organizer retired');

        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => 'ARCHIVED']);
        $this->assertModelExists($member);
        $this->assertDatabaseHas('outbox_events', ['topic' => 'organization.archived.v1']);
        $this->assertSame(now()->toISOString(), $organization->fresh()->archived_at->toISOString());
        try {
            app(OrganizationService::class)->update($actor, $organization->public_id, ['name' => 'Reopened']);
            $this->fail('Archived organizations must not be modified.');
        } catch (ApiException $exception) {
            $this->assertSame('STATE_TRANSITION_INVALID', $exception->errorCode);
        }
    }

    public static function organizationMutations(): array
    {
        return ['create' => ['create'], 'update' => ['update'], 'add' => ['add'], 'role' => ['role'], 'remove' => ['remove'], 'archive' => ['archive']];
    }

    #[DataProvider('organizationMutations')]
    public function test_outbox_failure_rolls_back_domain_and_audit(string $operation): void
    {
        $actor = User::factory()->create();
        RoleAssignment::factory()->create(['user_id' => $actor->id]);
        $organization = Organization::factory()->create();
        $member = OrganizationMember::factory()->gameMaster()->create(['organization_id' => $organization->id]);
        $target = User::factory()->create();
        $this->mock(OutboxService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Outbox unavailable'));
        $service = app(OrganizationService::class);

        try {
            match ($operation) {
                'create' => $service->create($actor, ['name' => 'Rollback', 'slug' => 'rollback']),
                'update' => $service->update($actor, $organization->public_id, ['name' => 'Rollback']),
                'add' => $service->addMember($actor, $organization->public_id, ['user_id' => $target->public_id, 'role' => 'STAFF']),
                'role' => $service->changeRole($actor, $organization->public_id, $member->user->public_id, ['role' => 'STAFF', 'reason' => 'Rollback']),
                'remove' => $service->removeMember($actor, $organization->public_id, $member->user->public_id),
                'archive' => $service->archive($actor, $organization->public_id, 'Rollback'),
            };
            $this->fail('An unavailable outbox must abort the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Outbox unavailable', $exception->getMessage());
        }
        $this->assertSame($organization->name, $organization->fresh()->name);
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('organization_members', ['id' => $member->id, 'role' => 'GAMEMASTER', 'status' => 'ACTIVE']);
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('organization_members', 1);
        $this->assertDatabaseCount('organization_settings', 0);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_bootstrap_is_once_only_and_privileged_grants_and_revocations_are_audited(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->artisan('organizations:bootstrap-super-admin', ['user_id' => $actor->public_id, '--reason' => 'Initial operator'])->assertSuccessful();
        $this->artisan('organizations:bootstrap-super-admin', ['user_id' => $target->public_id, '--reason' => 'Must fail'])->assertFailed();
        $roles = app(GlobalRoleService::class);

        $roles->grant($actor, $target->public_id, 'Second operator');
        $this->assertTrue(app(OrganizationScope::class)->isSuperAdmin($target));
        $roles->revoke($actor, $target->public_id, 'Access ended');

        $this->assertFalse(app(OrganizationScope::class)->isSuperAdmin($target));
        $this->assertDatabaseCount('role_assignments', 2);
        $this->assertDatabaseHas('audit_events', ['action' => 'role_assignment.granted', 'actor_type' => 'system', 'reason' => 'Initial operator']);
        $this->assertDatabaseHas('audit_events', ['action' => 'role_assignment.revoked', 'actor_id' => $actor->id, 'reason' => 'Access ended']);
    }

    public function test_organization_admin_cannot_grant_itself_super_admin(): void
    {
        $member = OrganizationMember::factory()->administrator()->create();
        try {
            app(GlobalRoleService::class)->grant($member->user, $member->user->public_id, 'Attempted escalation');
            $this->fail('An organization admin cannot grant global privileges.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('role_assignments', 0);
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    public function test_settings_seed_is_idempotent_and_never_changes_commercial_values(): void
    {
        $organization = Organization::factory()->create();
        $settings = OrganizationSetting::factory()->create(['commercial_settings_json' => ['future' => true]]);

        $this->seed(OrganizationSettingsSeeder::class);
        $this->seed(OrganizationSettingsSeeder::class);

        $this->assertDatabaseCount('organization_settings', 2);
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $organization->id]);
        $this->assertSame(['future' => true], $settings->fresh()->commercial_settings_json);
    }

    public function test_audit_failure_rolls_back_organization_settings_and_outbox(): void
    {
        $actor = User::factory()->create();
        RoleAssignment::factory()->create(['user_id' => $actor->id]);
        $this->mock(AuditService::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));

        try {
            app(OrganizationService::class)->create($actor, ['name' => 'Rollback', 'slug' => 'rollback']);
            $this->fail('A failed audit must roll back the whole mutation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_settings', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_all_canonical_events_use_public_references_and_correlated_audit(): void
    {
        $actor = User::factory()->create();
        RoleAssignment::factory()->create(['user_id' => $actor->id]);
        $target = User::factory()->create();
        request()->attributes->set('request_id', 'tenant-lifecycle');
        $service = app(OrganizationService::class);

        $organization = $service->create($actor, ['name' => 'Organizer', 'slug' => 'organizer']);
        $service->update($actor, $organization->public_id, ['name' => 'Updated']);
        $service->addMember($actor, $organization->public_id, ['user_id' => $target->public_id, 'role' => 'GAMEMASTER']);
        $service->changeRole($actor, $organization->public_id, $target->public_id, ['role' => 'STAFF', 'reason' => 'Duties changed']);
        $service->removeMember($actor, $organization->public_id, $target->public_id);
        $service->archive($actor, $organization->public_id, 'Organizer retired');

        $events = DB::table('outbox_events')->orderBy('id')->get();
        $this->assertSame(['organization.created.v1', 'organization.updated.v1', 'organization.member_added.v1', 'organization.member_role_changed.v1', 'organization.member_removed.v1', 'organization.archived.v1'], $events->pluck('topic')->all());
        $this->assertDatabaseCount('audit_events', 6);
        foreach ($events as $event) {
            $payload = json_decode($event->payload, true);
            $this->assertSame($event->event_id, $payload['event_id']);
            $this->assertSame($event->topic, $payload['event_type']);
            $this->assertSame($organization->public_id, $payload['organization_id']);
            $this->assertSame($organization->public_id, $event->aggregate_id);
            $this->assertSame('user', $payload['actor']['type']);
            $this->assertSame($actor->public_id, $payload['actor']['id']);
            $this->assertSame(1, $payload['payload_version']);
            $this->assertSame('organizations', $payload['producer']);
            $this->assertSame('tenant-lifecycle', $payload['request_id']);
            $this->assertStringEndsWith('Z', $payload['occurred_at']);
            $this->assertSame('pending', $event->status);
            $this->assertSame(0, $event->attempts);
            $this->assertDatabaseHas('audit_events', ['action' => $event->topic, 'organization_id' => $organization->id, 'request_id' => 'tenant-lifecycle']);
        }
        $stored = json_encode($events);
        $this->assertStringNotContainsString($actor->email, $stored);
        $this->assertStringNotContainsString($actor->password_hash, $stored);
        $this->assertStringNotContainsString($target->email, $stored);
    }
}

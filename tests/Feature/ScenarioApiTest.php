<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\OrganizationMember;
use App\Models\RoleAssignment;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Scenarios\ScenarioGraph;
use App\Services\Scenarios\ScenarioScope;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ScenarioFixtures;
use Tests\TestCase;

class ScenarioApiTest extends TestCase
{
    use RefreshDatabase, ScenarioFixtures;

    public function test_admin_creates_updates_and_reads_public_scenario_and_versions(): void
    {
        [$admin,$scenario] = $this->scenarioFixture();
        $this->scenarioAuth($admin);
        $id = $this->postJson('/api/v1/scenarios', ['organization_id' => $scenario->organization->public_id, 'title' => 'City mystery'])
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')->json('data.id');
        $this->assertTrue(Str::isUuid($id));
        $this->patchJson('/api/v1/scenarios/'.$id, ['theme' => 'History'])->assertOk()->assertJsonPath('data.theme', 'History');
        $this->getJson('/api/v1/scenarios/'.$id)->assertOk()->assertJsonPath('data.organization_id', $scenario->organization->public_id)->assertJsonMissingPath('data.created_by');
        $this->getJson('/api/v1/scenarios')->assertOk()->assertJsonCount(2, 'data');
        $version = $this->postJson('/api/v1/scenarios/'.$id.'/versions', [])->assertCreated()->assertJsonPath('data.version_no', 1)->json('data');
        $this->postJson('/api/v1/scenarios/'.$id.'/versions', [])->assertCreated()->assertJsonPath('data.version_no', 2);
        $this->getJson('/api/v1/scenarios/'.$id.'/versions')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/scenario-versions/'.$version['id'])->assertOk()->assertJsonPath('data.status', 'DRAFT');
        $this->assertDatabaseHas('audit_events', ['action' => 'scenario.created.v1', 'resource_id' => $id]);
        $this->assertDatabaseHas('outbox_events', ['topic' => 'scenario.created.v1']);
    }

    public function test_save_validate_publish_duplicate_and_structural_simulation(): void
    {
        [$admin,,$version] = $this->scenarioFixture(false);
        $this->scenarioAuth($admin);
        $base = '/api/v1/scenario-versions/'.$version->public_id;
        $saved = $this->putJson($base.'/graph', $this->simpleGraph() + ['revision_token' => $version->revision_token])
            ->assertOk()->assertJsonPath('data.validation.status', 'PASS')->json('data');
        $this->assertNotSame($version->revision_token, $saved['revision_token']);
        $this->postJson($base.'/simulate', [])->assertOk()->assertJsonPath('data.runtime_executed', false)->assertJsonPath('data.reachable_nodes', ['start', 'finish']);
        $this->postJson($base.'/validate', [])->assertOk()->assertJsonPath('data.status', 'PASS');
        $this->withHeader('X-Request-Id', 'scenario-publish-correlation')->postJson($base.'/publish', ['revision_token' => $saved['revision_token']])
            ->assertOk()->assertJsonPath('data.status', 'PUBLISHED');
        $this->postJson($base.'/publish', ['revision_token' => $saved['revision_token']])->assertOk();
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'scenario_version.published.v1')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'scenario_version.published.v1', 'request_id' => 'scenario-publish-correlation']);
        $copy = $this->withHeader('Idempotency-Key', 'duplicate-test-key')->postJson($base.'/duplicate', [])->assertCreated()->assertJsonPath('data.status', 'DRAFT')->json('data');
        $this->withHeader('Idempotency-Key', 'duplicate-test-key')->postJson($base.'/duplicate', [])->assertCreated()->assertJsonPath('data.id', $copy['id']);
        $this->assertNotSame($version->public_id, $copy['id']);
        $this->assertNotSame($saved['revision_token'], $copy['revision_token']);
        $this->withHeader('Idempotency-Key', 'duplicate-test-key')->postJson($base.'/duplicate', ['reason' => 'Changed'])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $copyModel = ScenarioVersion::where('public_id', $copy['id'])->sole();
        $this->assertEquals(app(ScenarioGraph::class)->read($version), app(ScenarioGraph::class)->read($copyModel));
    }

    public function test_stale_save_and_stale_validation_cannot_publish(): void
    {
        [$admin,,$version] = $this->scenarioFixture();
        $this->scenarioAuth($admin);
        $base = '/api/v1/scenario-versions/'.$version->public_id;
        $this->postJson($base.'/validate', [])->assertOk();
        $new = $this->patchJson($base, ['revision_token' => $version->revision_token, 'difficulty' => 'Moderate'])->assertOk()->json('data.revision_token');
        $this->putJson($base.'/graph', $this->simpleGraph() + ['revision_token' => $version->revision_token])->assertConflict();
        $this->postJson($base.'/publish', ['revision_token' => $new])->assertConflict()->assertJsonPath('error.code', 'SCENARIO_VERSION_INVALID');
        $this->assertSame('DRAFT', $version->fresh()->status);
        $this->assertSame(0, DB::table('outbox_events')->where('topic', 'scenario_version.published.v1')->count());
    }

    public function test_published_source_is_immutable_and_archive_preserves_graph(): void
    {
        [$admin,$scenario,$version] = $this->scenarioFixture();
        $this->publishFixture($admin, $version);
        $this->scenarioAuth($admin);
        $before = app(ScenarioGraph::class)->read($version);
        $this->patchJson('/api/v1/scenario-versions/'.$version->public_id, ['revision_token' => $version->revision_token, 'difficulty' => 'Changed'])->assertConflict();
        $this->putJson('/api/v1/scenario-versions/'.$version->public_id.'/graph', $this->simpleGraph() + ['revision_token' => $version->revision_token])->assertConflict();
        app(ScenarioService::class)->execute($admin, 'archiveVersion', [], $version->public_id);
        app(ScenarioService::class)->execute($admin, 'archive', [], $scenario->public_id);
        $this->getJson('/api/v1/scenario-versions/'.$version->public_id.'/graph')->assertOk();
        $this->assertSame($before, app(ScenarioGraph::class)->read($version));
        $this->assertSame('ARCHIVED', $version->fresh()->status);
        $this->assertDatabaseCount('scenario_nodes', 2);
    }

    public static function forbiddenActors(): array
    {
        return ['player' => ['player'], 'gamemaster' => ['GAMEMASTER'], 'staff' => ['STAFF'], 'other tenant' => ['other']];
    }

    #[DataProvider('forbiddenActors')]
    public function test_unprivileged_actors_cannot_read_or_publish_secret_source(string $role): void
    {
        [$admin,$scenario,$version] = $this->scenarioFixture();
        $actor = User::factory()->create();
        if (in_array($role, ['GAMEMASTER', 'STAFF'], true)) {
            OrganizationMember::factory()->create(['organization_id' => $scenario->organization_id, 'user_id' => $actor->id, 'role' => $role]);
        }
        if ($role === 'other') {
            OrganizationMember::factory()->administrator()->create(['user_id' => $actor->id]);
        }
        $this->assertFalse(Gate::forUser($actor)->allows('publish', $scenario));
        $query = app(ScenarioScope::class)->scenarios($actor);
        $this->assertSame(0, $query->count());
        $this->scenarioAuth($actor);
        foreach (['/api/v1/scenarios/'.$scenario->public_id, '/api/v1/scenario-versions/'.$version->public_id, '/api/v1/scenario-versions/'.$version->public_id.'/graph'] as $url) {
            $this->getJson($url)->assertNotFound();
        }
        $this->postJson('/api/v1/scenario-versions/'.$version->public_id.'/publish', ['revision_token' => $version->revision_token])->assertNotFound();
        $this->getJson('/api/v1/scenarios')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('DRAFT', $version->fresh()->status);
    }

    public function test_missing_authentication_and_numeric_identifiers_are_rejected(): void
    {
        $this->getJson('/api/v1/scenarios')->assertUnauthorized();
        [$admin,$scenario] = $this->scenarioFixture();
        $this->scenarioAuth($admin);
        $this->getJson('/api/v1/scenarios/'.$scenario->id)->assertNotFound();
    }

    public function test_injection_and_missing_revision_are_rejected_without_writes(): void
    {
        [$admin,$scenario,$version] = $this->scenarioFixture();
        $this->scenarioAuth($admin);
        $this->patchJson('/api/v1/scenarios/'.$scenario->public_id, ['organization_id' => Str::uuid(), 'status' => 'PUBLISHED'])->assertUnprocessable();
        $this->patchJson('/api/v1/scenario-versions/'.$version->public_id, ['status' => 'PUBLISHED', 'revision_token' => $version->revision_token])->assertUnprocessable();
        $this->putJson('/api/v1/scenario-versions/'.$version->public_id.'/graph', $this->simpleGraph())->assertUnprocessable();
        $this->assertSame($version->revision_token, $version->fresh()->revision_token);
    }

    public function test_failed_validation_is_persisted_and_never_publishes(): void
    {
        [$admin,,$version] = $this->scenarioFixture(false);
        $this->scenarioAuth($admin);
        $this->postJson('/api/v1/scenario-versions/'.$version->public_id.'/validate', [])->assertOk()->assertJsonPath('data.status', 'FAIL');
        $this->assertDatabaseHas('scenario_validation_runs', ['scenario_version_id' => $version->id, 'status' => 'FAIL', 'revision_token' => $version->revision_token]);
        $this->assertDatabaseHas('outbox_events', ['topic' => 'scenario_version.validation_failed.v1']);
        $this->postJson('/api/v1/scenario-versions/'.$version->public_id.'/publish', ['revision_token' => $version->revision_token])->assertConflict();
    }

    public function test_authorization_is_rechecked_for_retained_query_and_idempotent_retry(): void
    {
        [$admin,$scenario,$version] = $this->scenarioFixture();
        $query = app(ScenarioScope::class)->scenarios($admin);
        app(ScenarioService::class)->execute($admin, 'duplicate', [], $version->public_id, 'retry-revoked-user');
        OrganizationMember::where('organization_id', $scenario->organization_id)->where('user_id', $admin->id)->update(['status' => 'REMOVED', 'left_at' => now()]);
        $this->assertSame(0, $query->count());
        $this->expectException(ApiException::class);
        app(ScenarioService::class)->execute($admin, 'duplicate', [], $version->public_id, 'retry-revoked-user');
    }

    public function test_publish_event_failure_rolls_back_content_state_audit_and_idempotency(): void
    {
        [$admin, $scenario, $version] = $this->scenarioFixture();
        $service = app(ScenarioService::class);
        $service->execute($admin, 'validate', [], $version->public_id);
        $before = DB::table('audit_events')->count();
        DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT test_reject_publish CHECK (topic <> 'scenario_version.published.v1')");
        try {
            $service->execute($admin, 'publish', ['revision_token' => $version->revision_token], $version->public_id, 'publish-atomic-failure');
            $this->fail('Publication must roll back when its outbox write fails.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->assertSame('DRAFT', $version->fresh()->status);
        $this->assertSame('DRAFT', $scenario->fresh()->status);
        $this->assertSame($before, DB::table('audit_events')->count());
        DB::statement('ALTER TABLE outbox_events DROP CONSTRAINT test_reject_publish');
        $result = $service->execute($admin, 'publish', ['revision_token' => $version->revision_token], $version->public_id, 'publish-atomic-failure');
        $this->assertSame('PUBLISHED', $result['status']);
    }

    public function test_publish_key_replay_is_safe_and_conflicting_payload_is_rejected(): void
    {
        [$admin, , $version] = $this->scenarioFixture();
        $service = app(ScenarioService::class);
        $service->execute($admin, 'validate', [], $version->public_id);
        $this->scenarioAuth($admin);
        $url = '/api/v1/scenario-versions/'.$version->public_id.'/publish';
        $payload = ['revision_token' => $version->revision_token];
        $this->withHeader('Idempotency-Key', 'publish-replay-key')->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.status', 'PUBLISHED');
        $this->postJson($url, $payload + ['reason' => 'Changed payload'])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'scenario_version.published.v1')->count());
    }

    public function test_suspended_account_and_archived_organization_cannot_edit_content(): void
    {
        [$admin, $scenario, $version] = $this->scenarioFixture();
        $this->scenarioAuth($admin);
        $admin->forceFill(['status' => 'SUSPENDED'])->save();
        $this->patchJson('/api/v1/scenarios/'.$scenario->public_id, ['title' => 'Blocked'])->assertForbidden();
        $admin->forceFill(['status' => 'ACTIVE'])->save();
        $scenario->organization->forceFill(['status' => 'ARCHIVED', 'archived_at' => now()])->save();
        $this->patchJson('/api/v1/scenarios/'.$scenario->public_id, ['title' => 'Blocked'])->assertConflict();
        $this->assertSame($version->revision_token, $version->fresh()->revision_token);
    }

    public function test_superadmin_intervention_requires_reason_and_is_audited(): void
    {
        [, $scenario] = $this->scenarioFixture();
        $actor = User::factory()->create();
        RoleAssignment::factory()->create(['user_id' => $actor->id]);
        $this->scenarioAuth($actor);
        $url = '/api/v1/scenarios/'.$scenario->public_id;
        $this->patchJson($url, ['title' => 'Recovered'])->assertUnprocessable();
        $this->patchJson($url, ['title' => 'Recovered', 'reason' => 'Authorized content recovery'])->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'scenario.updated.v1', 'reason' => 'Authorized content recovery', 'actor_id' => $actor->id]);
    }
}

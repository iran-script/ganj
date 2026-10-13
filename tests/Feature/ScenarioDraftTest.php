<?php

namespace Tests\Feature;

use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Scenarios\ScenarioGraph;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ScenarioFixtures;
use Tests\TestCase;

class ScenarioDraftTest extends TestCase
{
    use RefreshDatabase, ScenarioFixtures;

    public function test_publish_preserves_content_and_leaves_an_independent_editable_draft(): void
    {
        [$admin, $scenario, $v1] = $this->scenarioFixture();
        $before = app(ScenarioGraph::class)->read($v1);
        $this->publishFixture($admin, $v1);
        $this->scenarioAuth($admin);
        $draft = $this->postJson('/api/v1/scenarios/'.$scenario->public_id.'/draft')->assertOk()->assertJsonPath('data.version_no', 2)->json('data');
        $this->postJson('/api/v1/scenarios/'.$scenario->public_id.'/draft')->assertOk()->assertJsonPath('data.id', $draft['id']);
        $changed = $before;
        $changed['nodes'][0]['title'] = 'Second edition';
        $saved = $this->putJson('/api/v1/scenario-versions/'.$draft['id'].'/graph', $changed + ['revision_token' => $draft['revision_token']])->assertOk()->json('data');
        $this->postJson('/api/v1/scenario-versions/'.$draft['id'].'/validate')->assertOk()->assertJsonPath('data.status', 'PASS');

        $this->postJson('/api/v1/scenario-versions/'.$draft['id'].'/publish', ['revision_token' => $saved['revision_token']])->assertOk()->assertJsonPath('data.status', 'PUBLISHED');

        $this->assertSame($before, app(ScenarioGraph::class)->read($v1));
        $this->assertSame('PUBLISHED', $v1->fresh()->status);
        $this->assertSame(2, $scenario->versions()->where('status', 'PUBLISHED')->count());
        $next = $scenario->versions()->where('status', 'DRAFT')->sole();
        $this->assertSame(3, $next->version_no);
        $this->assertSame('Second edition', app(ScenarioGraph::class)->read($next)['nodes'][0]['title']);
        $this->patchJson('/api/v1/scenarios/'.$scenario->public_id, ['title' => 'Editable container'])->assertOk()->assertJsonPath('data.title', 'Editable container');
        $this->getJson('/api/v1/scenarios/'.$scenario->public_id.'/versions')->assertOk()->assertJsonPath('data.0.version_no', 3)->assertJsonPath('data.1.version_no', 2)->assertJsonPath('data.2.version_no', 1);
        $this->putJson('/api/v1/scenario-versions/'.$draft['id'].'/graph', $before + ['revision_token' => $saved['revision_token']])->assertConflict();
    }

    public function test_existing_edited_draft_is_not_overwritten_when_another_draft_is_published(): void
    {
        [$admin, $scenario, $first] = $this->scenarioFixture();
        $second = ScenarioVersion::factory()->create(['scenario_id' => $scenario->id, 'version_no' => 2, 'difficulty' => 'Keep me']);

        $this->publishFixture($admin, $first);

        $this->assertSame('Keep me', $second->fresh()->difficulty);
        $this->assertSame(2, $scenario->versions()->count());
    }

    public function test_legacy_published_scenario_is_normalized_idempotently_without_replacing_ids(): void
    {
        [$admin, $scenario, $source] = $this->scenarioFixture();
        $source->forceFill(['status' => 'PUBLISHED', 'published_at' => now(), 'published_by' => $admin->id])->save();
        $before = app(ScenarioGraph::class)->read($source);

        $this->artisan('nexo:scenarios:ensure-draft', ['scenario' => [$scenario->public_id], '--actor' => $admin->public_id, '--reason' => 'Normalize pilot'])->assertSuccessful();
        $this->artisan('nexo:scenarios:ensure-draft', ['scenario' => [$scenario->public_id], '--actor' => $admin->public_id, '--reason' => 'Retry normalization'])->assertSuccessful();

        $draft = $scenario->versions()->where('status', 'DRAFT')->sole();
        $this->assertSame($before, app(ScenarioGraph::class)->read($draft));
        $this->assertSame($before, app(ScenarioGraph::class)->read($source));
        $this->assertNotSame($source->id, $draft->id);
        $this->assertSame(2, $scenario->versions()->count());
    }

    public function test_draft_recovery_requires_authentication_and_tenant_authorization(): void
    {
        [$admin, $scenario, $version] = $this->scenarioFixture();
        $url = '/api/v1/scenarios/'.$scenario->public_id.'/draft';
        $this->postJson($url)->assertUnauthorized();
        $this->scenarioAuth(User::factory()->create());
        $this->postJson($url)->assertNotFound();
        $this->putJson('/api/v1/scenario-versions/'.$version->public_id.'/graph', $this->simpleGraph() + ['revision_token' => $version->revision_token])->assertNotFound();
        [$other] = $this->scenarioFixture();
        $this->scenarioAuth($other);
        $this->postJson($url)->assertNotFound();
        $this->putJson('/api/v1/scenario-versions/'.$version->public_id.'/graph', $this->simpleGraph() + ['revision_token' => $version->revision_token])->assertNotFound();
        $this->assertSame(1, $scenario->versions()->count());
    }

    public function test_empty_scenario_gets_a_draft_and_archived_scenario_rejects_recovery(): void
    {
        [$admin, $scenario] = $this->scenarioFixture();
        $empty = Scenario::factory()->create(['organization_id' => $scenario->organization_id, 'created_by' => $admin->id]);
        $this->scenarioAuth($admin);
        $this->postJson('/api/v1/scenarios/'.$empty->public_id.'/draft')->assertOk()->assertJsonPath('data.status', 'DRAFT');
        app(ScenarioService::class)->execute($admin, 'archive', [], $empty->public_id);

        $this->postJson('/api/v1/scenarios/'.$empty->public_id.'/draft')->assertConflict();
    }

    public function test_version_history_cursor_preserves_numeric_order(): void
    {
        [$admin, $scenario] = $this->scenarioFixture();
        ScenarioVersion::factory()->create(['scenario_id' => $scenario->id, 'version_no' => 2]);
        ScenarioVersion::factory()->create(['scenario_id' => $scenario->id, 'version_no' => 10]);
        $this->scenarioAuth($admin);
        $url = '/api/v1/scenarios/'.$scenario->public_id.'/versions?limit=2';

        $cursor = $this->getJson($url)->assertOk()->assertJsonPath('data.0.version_no', 10)->assertJsonPath('data.1.version_no', 2)->json('meta.pagination.next_cursor');

        $this->getJson($url.'&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.version_no', 1)->assertJsonPath('meta.pagination.has_more', false);
        $invalid = base64_encode(json_encode(['public_id' => $scenario->public_id, 'version_no' => '1 OR 1=1', '_pointsToNextItems' => true], JSON_THROW_ON_ERROR));
        $this->getJson($url.'&cursor='.urlencode($invalid))->assertUnprocessable();
    }
}

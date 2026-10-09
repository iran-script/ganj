<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\OrganizationMember;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ScenarioFixtures;
use Tests\TestCase;

class ScenarioConcurrencyTest extends TestCase
{
    use DatabaseMigrations, ScenarioFixtures;

    public static function races(): array
    {
        return ['save save' => ['save save', 'CONFLICT'], 'save publish' => ['save publish', 'CONFLICT'],
            'publish save' => ['publish save', 'STATE_TRANSITION_INVALID'], 'validate save' => ['validate save', null],
            'publish publish' => ['publish publish', null], 'duplicate duplicate' => ['duplicate duplicate', null],
            'archive publish' => ['archive publish', 'STATE_TRANSITION_INVALID']];
    }

    #[DataProvider('races')]
    public function test_competing_commands_serialize_and_recheck_revision_and_state(string $race, ?string $error): void
    {
        [$actor,$scenario,$version] = $this->scenarioFixture();
        $other = User::factory()->create();
        OrganizationMember::factory()->administrator()->create(['organization_id' => $scenario->organization_id, 'user_id' => $other->id]);
        $service = app(ScenarioService::class);
        $service->execute($actor, 'validate', [], $version->public_id);
        $save = ['saveGraph', $this->simpleGraph() + ['revision_token' => $version->revision_token], $version->public_id];
        $publish = ['publish', ['revision_token' => $version->revision_token], $version->public_id];
        [$one,$two] = match ($race) {
            'save save' => [$save, $save], 'save publish' => [$save, $publish], 'publish save' => [$publish, $save],
            'validate save' => [['validate', [], $version->public_id], $save],
            'publish publish' => [$publish, $publish],
            'duplicate duplicate' => [['duplicate', [], $version->public_id], ['duplicate', [], $version->public_id]],
            default => [['archive', [], $scenario->public_id], $publish],
        };
        $default = DB::getDefaultConnection();
        config(['database.connections.scenario_probe' => config('database.connections.'.$default)]);
        $primary = DB::connection($default);
        $probe = DB::connection('scenario_probe');
        $probe->statement("SET lock_timeout = '200ms'");
        $primary->beginTransaction();
        try {
            $service->execute($actor, ...$one);
            DB::setDefaultConnection('scenario_probe');
            try {
                app(ScenarioService::class)->execute($other, ...$two);
                $this->fail('The contender bypassed the content lock.');
            } catch (QueryException $e) {
                $this->assertSame('55P03', $e->errorInfo[0]);
            }
            DB::setDefaultConnection($default);
            $primary->commit();
            DB::setDefaultConnection('scenario_probe');
            try {
                $result = app(ScenarioService::class)->execute($other, ...$two);
                $this->assertNull($error);
                $this->assertNotEmpty($result);
            } catch (ApiException $e) {
                $this->assertSame($error, $e->errorCode);
            }
        } finally {
            DB::setDefaultConnection($default);
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge('scenario_probe');
        }
        if ($race === 'publish publish') {
            $this->assertSame(1, DB::table('outbox_events')->where('topic', 'scenario_version.published.v1')->count());
        }
        if ($race === 'duplicate duplicate') {
            $this->assertSame([1, 2, 3], ScenarioVersion::where('scenario_id', $scenario->id)->orderBy('version_no')->pluck('version_no')->all());
        }
        if ($race === 'validate save') {
            $this->assertNotSame($version->fresh()->revision_token, DB::table('scenario_validation_runs')->where('scenario_version_id', $version->id)->orderByDesc('id')->value('revision_token'));
        }
    }
}

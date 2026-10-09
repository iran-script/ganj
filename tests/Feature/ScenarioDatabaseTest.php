<?php

namespace Tests\Feature;

use App\Models\ScenarioCondition;
use App\Models\ScenarioNode;
use App\Models\ScenarioSubflowVersion;
use App\Models\ScenarioVariable;
use App\Models\ScenarioVersion;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ScenarioFixtures;
use Tests\TestCase;

class ScenarioDatabaseTest extends TestCase
{
    use RefreshDatabase, ScenarioFixtures;

    public static function frozenMutations(): array
    {
        return ['node update' => ['scenario_nodes', 'update'], 'node insert' => ['scenario_nodes', 'insert'], 'node delete' => ['scenario_nodes', 'delete'],
            'edge update' => ['scenario_edges', 'update'], 'edge delete' => ['scenario_edges', 'delete'], 'variable update' => ['scenario_variables', 'update'],
            'condition update' => ['scenario_conditions', 'update'], 'version update' => ['scenario_versions', 'update'], 'version delete' => ['scenario_versions', 'delete']];
    }

    #[DataProvider('frozenMutations')]
    public function test_postgresql_rejects_published_content_mutation(string $table, string $operation): void
    {
        [$admin,,$version] = $this->scenarioFixture();
        ScenarioVariable::factory()->create(['scenario_version_id' => $version->id]);
        ScenarioCondition::factory()->create(['scenario_version_id' => $version->id]);
        $this->publishFixture($admin, $version);
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($table, $operation, $version): void {
            $query = DB::table($table)->where($table === 'scenario_versions' ? 'id' : 'scenario_version_id', $version->id);
            if ($operation === 'delete') {
                $query->delete();
            } elseif ($operation === 'insert') {
                ScenarioNode::factory()->create(['scenario_version_id' => $version->id]);
            } else {
                $values = match ($table) {
                    'scenario_nodes' => ['title' => 'Changed'],'scenario_edges' => ['priority' => 4],'scenario_variables' => ['writable' => false],'scenario_conditions' => ['position' => 4],default => ['difficulty' => 'Changed']
                };
                $query->update($values);
            }
        });
    }

    public function test_cross_version_edge_reference_is_rejected_by_foreign_key(): void
    {
        [,, $version] = $this->scenarioFixture();
        $other = ScenarioNode::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn (): int => DB::table('scenario_edges')->where('scenario_version_id', $version->id)->update(['to_node_id' => $other->id]));
    }

    public function test_duplicate_version_number_is_rejected(): void
    {
        [,$scenario] = $this->scenarioFixture();
        $this->expectException(QueryException::class);
        DB::transaction(fn (): ScenarioVersion => ScenarioVersion::factory()->create(['scenario_id' => $scenario->id, 'version_no' => 1]));
    }

    public function test_validation_history_is_append_only(): void
    {
        [$admin,,$version] = $this->scenarioFixture();
        app(ScenarioService::class)->execute($admin, 'validate', [], $version->public_id);
        $this->expectException(QueryException::class);
        DB::transaction(fn (): int => DB::table('scenario_validation_runs')->where('scenario_version_id', $version->id)->update(['status' => 'FAIL']));
    }

    public function test_published_subflow_graph_cannot_be_changed(): void
    {
        $sub = ScenarioSubflowVersion::factory()->create(['status' => 'PUBLISHED', 'published_at' => now()]);
        $this->expectException(QueryException::class);
        DB::transaction(fn (): bool => $sub->forceFill(['graph_json' => []])->save());
    }
}

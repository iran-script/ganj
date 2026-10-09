<?php

namespace Tests\Feature;

use App\Models\RuntimeEvent;
use App\Models\TeamNodeInstance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class RuntimeDatabaseTest extends TestCase
{
    use RefreshDatabase, RuntimeFixtures;

    public static function corruptions(): array
    {
        return [['duplicate instance'], ['duplicate node'], ['invalid status'], ['pin mutation'], ['foreign node'], ['event update'], ['event delete'], ['invalid timer'], ['fifth slot']];
    }

    #[DataProvider('corruptions')]
    public function test_database_rejects_runtime_corruption(string $case): void
    {
        [, , $instance, $player, $node] = $this->runtimeFixture(teamCount: 2);
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($case, $instance, $node, $player): void {
            match ($case) {
                'duplicate instance' => DB::table('team_scenario_instances')->insert(array_merge($instance->getAttributes(), ['id' => 9999999, 'public_id' => (string) Str::uuid()])),
                'duplicate node' => DB::table('team_node_instances')->insert(array_merge($node->getAttributes(), ['id' => 9999999, 'public_id' => (string) Str::uuid()])),
                'invalid status' => DB::table('team_node_instances')->where('id', $node->id)->update(['status' => 'CLIENT_COMPLETED']),
                'pin mutation' => DB::table('team_scenario_instances')->where('id', $instance->id)->update(['random_seed' => 'changed']),
                'foreign node' => DB::table('team_scenario_instances')->where('id', $instance->id)->update(['current_node_instance_id' => TeamNodeInstance::where('team_scenario_instance_id', '<>', $instance->id)->firstOrFail()->id]),
                'event update' => RuntimeEvent::query()->update(['event_type' => 'changed']),
                'event delete' => RuntimeEvent::query()->delete(),
                'invalid timer' => DB::table('runtime_timers')->update(['accumulated_paused_seconds' => -1]),
                'fifth slot' => DB::table('player_role_assignments')->insert(['public_id' => (string) Str::uuid(), 'team_scenario_instance_id' => $instance->id,
                    'node_instance_id' => $node->id, 'game_session_player_id' => $player->id, 'role_code' => 'DECODER', 'slot_no' => 5, 'assigned_at' => now(), 'assignment_source' => 'TEST']),
            };
        });
    }
}

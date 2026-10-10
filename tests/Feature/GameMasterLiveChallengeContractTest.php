<?php

namespace Tests\Feature;

use Tests\TestCase;

class GameMasterLiveChallengeContractTest extends TestCase
{
    public function test_phase_2_5_uses_canonical_routes_and_persistence(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_10_10_000003_create_live_challenge_tables.php'));
        $routes = file_get_contents(base_path('routes/api.php'));
        $service = file_get_contents(base_path('app/Services/GameMaster/LiveChallengeService.php'));

        foreach (['challenge_templates', 'live_challenges', 'live_challenge_targets', 'live_challenge_submissions', 'session_messages'] as $table) {
            $this->assertStringContainsString("Schema::create('{$table}'", $migration);
        }
        foreach (['control-room', 'live-challenges', 'messages', 'rebind-device', 'disqualify'] as $route) {
            $this->assertStringContainsString($route, $routes);
        }
        foreach (['live_challenge.created.v1', 'live_challenge.target_acked.v1', 'live_challenge.target_started.v1', 'live_challenge.target_completed.v1', 'live_challenge.completed.v1', 'live_challenge.cancelled.v1', 'ScoringService', 'GameScope'] as $required) {
            $this->assertStringContainsString($required, $service);
        }
    }

    public function test_phase_2_5_keeps_parallel_challenges_and_safe_pilot_scope(): void
    {
        $service = file_get_contents(base_path('app/Services/GameMaster/LiveChallengeService.php'));
        $docs = file_get_contents(base_path('docs/PHASE_2_5.md')).file_get_contents(base_path('docs/GAMEMASTER_CONTROL_ROOM_LIVE_CHALLENGES.md'));

        foreach (['PARALLEL', 'MESSAGE', 'TEAM_OBJECTIVE', 'SIDE_OBJECTIVE', 'reward', 'idempotency'] as $required) {
            $this->assertStringContainsString($required, $service);
        }
        foreach (['HOME VERIFICATION PENDING', 'PostgreSQL', 'Docker', 'realtime', 'Phase 2.6'] as $required) {
            $this->assertStringContainsString($required, $docs);
        }
        $this->assertStringNotContainsString('live_challenge_templates', $service);
        $this->assertStringNotContainsString('$score->score =', $service);
    }
}

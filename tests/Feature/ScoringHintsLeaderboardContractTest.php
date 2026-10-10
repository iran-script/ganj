<?php

namespace Tests\Feature;

use Tests\TestCase;

class ScoringHintsLeaderboardContractTest extends TestCase
{
    public function test_phase_2_3_persistence_and_routes_are_canonical(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_10_10_000002_create_scoring_hint_tables.php'));
        $routes = file_get_contents(base_path('routes/api.php'));
        $scoring = file_get_contents(base_path('app/Services/Scoring/ScoringService.php'));

        foreach (['score_events', 'team_scores', 'hint_definitions', 'hint_consumptions', 'score_events_append_only', 'hint_consumptions_append_only'] as $required) {
            $this->assertStringContainsString($required, $migration);
        }
        foreach (['/leaderboard', '/score', '/score-events', '/score-adjustments', '/hints', '/hints/consume'] as $required) {
            $this->assertStringContainsString($required, $routes);
        }
        foreach (['NODE_COMPLETION', 'FIRST_ATTEMPT_BONUS', 'NO_HINT_BONUS', 'HINT_PENALTY', 'MANUAL_ADJUSTMENT', 'score.finalized.v1'] as $required) {
            $this->assertStringContainsString($required, $scoring);
        }
        $leaderboard = file_get_contents(base_path('app/Services/Scoring/LeaderboardService.php'));
        foreach (['FULL', 'TOP_N', 'OWN_RANK_ONLY', 'HIDDEN_UNTIL_FINISH'] as $visibility) {
            $this->assertStringContainsString($visibility, $leaderboard);
        }
    }

    public function test_phase_2_3_does_not_expose_client_score_or_commerce_truth(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/Api/ScoringController.php'));
        $hintService = file_get_contents(base_path('app/Services/Scoring/HintService.php'));
        $openapi = file_get_contents(base_path('docs/specs/03_openapi.yaml'));

        $this->assertStringContainsString("'delta' => 'required|integer", $controller);
        $this->assertStringContainsString("'reason' => 'required|string", $controller);
        $this->assertStringContainsString('score-adjust:', $controller);
        $this->assertStringContainsString("'source' => 'DEFAULT'", $hintService);
        $this->assertStringNotContainsString('POST /api/v1/store', $openapi);
        $this->assertStringNotContainsString('POST /api/v1/payments', $openapi);
    }

    public function test_phase_2_3_documentation_keeps_home_verification_gate(): void
    {
        $phase = file_get_contents(base_path('docs/PHASE_2_3.md'));
        $guide = file_get_contents(base_path('docs/SCORING_HINTS_LEADERBOARD.md'));

        foreach (['HOME VERIFICATION PENDING', 'Phase 2.1', 'Phase 2.2', 'PostgreSQL', 'contention', 'Docker', 'Queue'] as $required) {
            $this->assertStringContainsString($required, $phase);
            $this->assertStringContainsString($required, $guide);
        }
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class QrTriggerSecurityContractTest extends TestCase
{
    public function test_qr_persistence_contract_is_hash_only_and_history_preserving(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_10_10_000001_create_qr_trigger_tables.php'));

        foreach (['qr_triggers', 'trigger_tokens', 'qr_scan_events', 'identifier_hash', 'token_hash', 'qr_trigger_status_check', 'qr_scan_result_check', 'dropIfExists'] as $required) {
            $this->assertStringContainsString($required, $migration);
        }
        $this->assertStringNotContainsString('$table->text(\'identifier\')', $migration);
        $this->assertStringNotContainsString('$table->text(\'token\')', $migration);
    }

    public function test_qr_source_enforces_context_security_and_runtime_boundaries(): void
    {
        $service = file_get_contents(base_path('app/Services/Qr/QrService.php'));
        $adapter = file_get_contents(base_path('app/Services/Runtime/QrVerificationAdapter.php'));

        foreach (['SESSION_NOT_RUNNING', 'DEVICE_NOT_BOUND', 'NODE_NOT_ACTIVE', 'SPECIFIC_PLAYER', 'QR_OUTSIDE_ALLOWED_AREA', 'RATE_LIMITED', 'random_bytes(32)', 'lockForUpdate', 'array_unique'] as $required) {
            $this->assertStringContainsString($required, $service);
        }
        foreach (['lockForUpdate', 'consumed_at', 'expires_at', 'qr.token_replay_detected.v1', 'qr.token_consumed.v1'] as $required) {
            $this->assertStringContainsString($required, $adapter);
        }
        foreach (["'next_node'", "'answer'", "'score'"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $service);
        }
    }

    public function test_qr_models_hide_internal_context_and_hashes(): void
    {
        $trigger = file_get_contents(base_path('app/Models/QrTrigger.php'));
        $token = file_get_contents(base_path('app/Models/TriggerToken.php'));

        foreach (['identifier_hash', 'organization_id', 'checkpoint_id'] as $hidden) {
            $this->assertStringContainsString($hidden, $trigger);
        }
        foreach (['token_hash', 'game_session_id', 'team_id', 'player_id', 'device_id', 'node_instance_id', 'qr_trigger_id'] as $hidden) {
            $this->assertStringContainsString($hidden, $token);
        }
    }

    public function test_qr_openapi_and_routes_remain_online_and_strict(): void
    {
        $spec = Yaml::parseFile(base_path('docs/specs/03_openapi.yaml'));
        $request = $spec['components']['schemas']['QrScanRequest'];
        $response = $spec['components']['schemas']['QrScanResponse'];
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route): bool => $route->getName() === 'qr.scan');

        $this->assertNotNull($route);
        $this->assertFalse($request['additionalProperties']);
        $this->assertTrue($response['properties']['online_required']['const']);
        foreach (['answer', 'score', 'next_node', 'identifier_hash', 'token_hash'] as $secret) {
            $this->assertArrayNotHasKey($secret, $response['properties']);
        }
    }

    public function test_home_verification_checklist_is_documented(): void
    {
        $documentation = file_get_contents(base_path('docs/PHASE_2_1.md')).file_get_contents(base_path('docs/QR_TRIGGER_VERIFICATION.md'));

        foreach (['migrate:fresh', 'rollback', 'Docker', 'live', 'ready', 'health', 'queue smoke', 'contention'] as $required) {
            $this->assertStringContainsString($required, $documentation);
        }
    }
}

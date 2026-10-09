<?php

namespace Tests\Feature;

use App\Models\RuntimeEvent;
use App\Models\TeamScenarioInstance;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Runtime\RuntimeEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class RuntimeSecurityTest extends TestCase
{
    use RefreshDatabase, RuntimeFixtures;

    public static function forgedTruth(): array
    {
        return [['completed', true], ['reached', true], ['next_node', 'finish'], ['score', 500], ['variables', ['code' => 7]],
            ['current_node', 'finish'], ['client_timestamp', '2099-01-01'], ['selected_option_refs', ['a', 'a']]];
    }

    #[DataProvider('forgedTruth')]
    public function test_client_truth_and_invalid_evidence_are_rejected(string $key, mixed $value): void
    {
        [, , , $player, $node] = $this->runtimeFixture();
        $this->runtimeAction($player, $node, 'start_node');
        $this->authenticateGame($player->user, $player->device);
        $this->withHeader('Idempotency-Key', 'forged-runtime-key')->postJson('/api/v1/runtime/nodes/'.$node->public_id.'/complete', [$key => $value])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertSame('ACTIVE', $node->fresh()->status);
        $this->assertSame(0, $node->fresh()->attempt_count);
        $this->assertDatabaseHas('runtime_commands', ['idempotency_key' => 'client:'.hash('sha256', 'forged-runtime-key'), 'status' => 'REJECTED']);
    }

    public function test_safe_reads_hide_answers_conditions_locked_content_and_numeric_ids(): void
    {
        [, $session, $instance, $player, $node] = $this->runtimeFixture();
        $this->authenticateGame($player->user, $player->device);
        $response = $this->getJson('/api/v1/game-sessions/'.$session->public_id.'/players/me/runtime')->assertOk();
        $json = $response->getContent();
        foreach (['Secret', 'expected_answer', 'correct_option_refs', 'condition_group_id', '"node_key":"finish"', 'random_seed', 'scenario_node_id', 'team_scenario_instance_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame($instance->public_id, $response->json('data.id'));
        $this->withHeader('Idempotency-Key', 'numeric-id-key')->postJson('/api/v1/runtime/nodes/'.$node->id.'/start')->assertNotFound();
    }

    public function test_other_team_and_cross_tenant_cannot_read_or_mutate_runtime(): void
    {
        [, $session, , $player, $node] = $this->runtimeFixture(teamCount: 2);
        $other = TeamScenarioInstance::where('game_session_id', $session->id)->orderByDesc('id')->firstOrFail();
        $this->authenticateGame($player->user, $player->device);
        $this->getJson('/api/v1/game-sessions/'.$session->public_id.'/teams/'.$other->registration->team->public_id.'/runtime')->assertNotFound();
        $otherPlayer = $other->registration->players()->firstOrFail();
        $this->authenticateGame($otherPlayer->user, $otherPlayer->device);
        $this->withHeader('Idempotency-Key', 'other-team-key')->postJson('/api/v1/runtime/nodes/'.$node->public_id.'/start')->assertNotFound();
        $this->authenticateGame(User::factory()->create());
        $this->getJson('/api/v1/game-sessions/'.$session->public_id.'/runtime')->assertNotFound();
    }

    public function test_wrong_revoked_and_unbound_devices_are_rejected_without_rebinding(): void
    {
        [, , , $player, $node] = $this->runtimeFixture();
        $engine = app(RuntimeEngine::class);
        $wrong = UserDevice::factory()->create(['user_id' => $player->user_id]);
        $this->assertDomainError('DEVICE_NOT_BOUND', fn () => $engine->execute($player->user, 'start_node', $node->public_id, [], 'wrong-device-key', $wrong));
        $this->assertDomainError('DEVICE_NOT_BOUND', fn () => $engine->execute($player->user, 'start_node', $node->public_id, [], 'missing-device-key'));
        $player->device->forceFill(['revoked_at' => now()])->save();
        $this->assertDomainError('DEVICE_NOT_BOUND', fn () => $engine->execute($player->user, 'start_node', $node->public_id, [], 'revoked-device-key', $player->device));
        $this->assertSame($player->active_device_id, $player->fresh()->active_device_id);
        $this->assertSame('AVAILABLE', $node->fresh()->status);
    }

    public function test_http_authentication_idempotency_and_request_correlation(): void
    {
        [, , , $player, $node] = $this->runtimeFixture();
        $url = '/api/v1/runtime/nodes/'.$node->public_id.'/start';
        $this->postJson($url)->assertUnauthorized();
        $this->authenticateGame($player->user, $player->device);
        $this->postJson($url)->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'http-start-key')->withHeader('X-Request-Id', 'runtime-correlation')->postJson($url)->assertOk();
        $event = RuntimeEvent::where('aggregate_id', $node->public_id)->where('event_type', 'runtime.node_started.v1')->sole();
        $this->assertSame('runtime-correlation', $event->payload['request_id']);
    }

    public static function ports(): array
    {
        return array_map(fn (string $port): array => ['App\\Services\\Runtime\\'.$port],
            ['LocationVerification', 'QRVerification', 'ARVerification', 'ScoreAction', 'HintAction', 'LiveChallengeTrigger']);
    }

    #[DataProvider('ports')]
    public function test_unimplemented_production_domains_fail_closed(string $port): void
    {
        [, , , $player, $node] = $this->runtimeFixture();
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => app($port)->execute($node, $player, ['completed' => true], 'port-command'));
        $this->assertSame('AVAILABLE', $node->fresh()->status);
    }
}

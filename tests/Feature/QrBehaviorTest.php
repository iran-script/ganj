<?php

namespace Tests\Feature;

use App\Models\Checkpoint;
use App\Models\GameSession;
use App\Models\PlayerRoleAssignment;
use App\Models\QrTrigger;
use App\Models\TriggerToken;
use App\Models\UserDevice;
use App\Services\Qr\QrService;
use App\Services\Runtime\RuntimeEngine;
use App\Services\Runtime\RuntimeRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class QrBehaviorTest extends TestCase
{
    use RefreshDatabase, RuntimeFixtures;

    private function qrFixture(array $config = [], bool $checkpoint = false): array
    {
        $id = (string) Str::uuid();
        $secret = bin2hex(random_bytes(32));
        $trigger = null;
        $fixture = $this->runtimeFixture($this->runtimeGraph('QR', ['trigger_id' => $id] + $config), beforePublish: function (GameSession $session) use ($id, $secret, $checkpoint, &$trigger): void {
            $trigger = QrTrigger::factory()->create(['public_id' => $id, 'organization_id' => $session->organization_id, 'identifier_hash' => hash('sha256', $secret),
                'checkpoint_id' => $checkpoint ? Checkpoint::factory()->create(['organization_id' => $session->organization_id])->id : null]);
        });
        [$admin, , $instance, $player, $node] = $fixture;
        if (($config['role_policy'] ?? null) === 'SPECIFIC_ROLE') {
            app(RuntimeEngine::class)->execute($admin, 'assign_role', $node->public_id,
                ['reason' => 'Fixture role', 'player_id' => $player->public_id, 'role_code' => $config['role_code'], 'slot_no' => 1], 'fixture-qr-role');
        }
        $this->runtimeAction($player, $node, 'start_node');

        return [...$fixture, $trigger, $secret];
    }

    public function test_valid_scan_retry_and_one_time_consumption_do_not_expose_internal_credentials(): void
    {
        [, $session, , $player, $node, , $trigger, $secret] = $this->qrFixture();
        $input = ['session_id' => $session->public_id, 'qr_identifier' => $secret];
        $service = app(QrService::class);

        $one = $service->scan($player->user, $player->device, $input, 'valid-scan-key');
        $two = $service->scan($player->user, $player->device, $input, 'valid-scan-key');
        ksort($one);
        ksort($two);

        $this->assertSame($one, $two);
        $this->assertTrue($one['online_required']);
        $this->assertDatabaseCount('trigger_tokens', 1);
        $this->assertDatabaseCount('qr_scan_events', 1);
        $this->assertSame(hash('sha256', $one['one_time_token']), TriggerToken::sole()->token_hash);
        foreach (['identifier_hash', 'token_hash', 'answer', 'score', 'next_node'] as $key) {
            $this->assertArrayNotHasKey($key, $one);
        }
        $this->assertStringNotContainsString($secret, json_encode(DB::table('outbox_events')->get()));
        $this->assertStringNotContainsString($one['one_time_token'], json_encode(DB::table('outbox_events')->get()));
        $this->assertArrayNotHasKey('identifier_hash', $trigger->toArray());
        $this->assertTrue($this->runtimeAction($player, $node, 'submit_action', ['qr_token' => $one['one_time_token']], 'consume-token-key')['accepted']);
        $this->assertNotNull(TriggerToken::sole()->consumed_at);
        $this->assertSame('COMPLETED', $node->fresh()->status);
        $this->assertDomainError('STATE_TRANSITION_INVALID', fn () => $this->runtimeAction($player, $node, 'submit_action', ['qr_token' => $one['one_time_token']]));
        $this->assertSame(1, DB::table('outbox_events')->where('topic', 'qr.token_consumed.v1')->count());
    }

    public static function locations(): array
    {
        return [['INSIDE', 0, true], ['OUTSIDE', 0, false], ['UNCERTAIN', 0, false], ['INSIDE', 301, false]];
    }

    #[DataProvider('locations')]
    public function test_scan_requires_fresh_inside_checkpoint_evidence(string $verdict, int $age, bool $accepted): void
    {
        $this->freezeTime();
        [, $session, , $player, $node, , $trigger, $secret] = $this->qrFixture(checkpoint: true);
        DB::table('geofence_evaluations')->insert(['game_session_id' => $session->id, 'player_id' => $player->id, 'checkpoint_id' => $trigger->checkpoint_id,
            'node_instance_id' => $node->id, 'location_event_id' => 1, 'location_event_received_at' => now()->subSeconds($age), 'result' => $verdict,
            'accuracy' => 3, 'evaluated_at' => now()->subSeconds($age), 'evidence_count' => 1, 'policy_hash' => str_repeat('a', 64)]);
        $scan = fn () => app(QrService::class)->scan($player->user, $player->device, ['session_id' => $session->public_id, 'qr_identifier' => $secret], 'location-scan-key');

        if ($accepted) {
            $this->assertTrue($scan()['accepted']);
        } else {
            $this->assertDomainError('QR_OUTSIDE_ALLOWED_AREA', $scan);
        }

        $this->assertDatabaseCount('trigger_tokens', $accepted ? 1 : 0);
        $this->assertDatabaseHas('qr_scan_events', ['result' => $accepted ? 'TOKEN_ISSUED' : 'REJECTED']);
    }

    public function test_revoked_device_cannot_replay_a_previously_successful_scan(): void
    {
        [, $session, , $player, , , , $secret] = $this->qrFixture();
        $scan = fn () => app(QrService::class)->scan($player->user, $player->device, ['session_id' => $session->public_id, 'qr_identifier' => $secret], 'device-scan-key');
        $scan();
        $player->device->forceFill(['revoked_at' => now()])->save();

        $this->assertDomainError('DEVICE_NOT_BOUND', $scan);

        $this->assertDatabaseCount('trigger_tokens', 1);
    }

    public function test_expired_token_cannot_complete_runtime(): void
    {
        $this->freezeTime();
        [, $session, , $player, $node, , , $secret] = $this->qrFixture();
        $scan = app(QrService::class)->scan($player->user, $player->device, ['session_id' => $session->public_id, 'qr_identifier' => $secret], 'expiring-scan-key');
        $this->travel(61)->seconds();

        $this->assertDomainError('QR_REPLAYED', fn () => $this->runtimeAction($player, $node, 'submit_action', ['qr_token' => $scan['one_time_token']]));

        $this->assertNull(TriggerToken::sole()->consumed_at);
        $this->assertSame('ACTIVE', $node->fresh()->status);
    }

    public function test_unknown_identifiers_are_rate_limited_and_tenant_trigger_is_rejected(): void
    {
        [, $session, , $player, , , , $secret] = $this->qrFixture();
        QrTrigger::factory()->create(['identifier_hash' => hash('sha256', 'other-tenant-opaque-identifier')]);
        $this->assertDomainError('QR_INVALID', fn () => app(QrService::class)->scan($player->user, $player->device,
            ['session_id' => $session->public_id, 'qr_identifier' => 'other-tenant-opaque-identifier'], 'other-tenant-key'));
        for ($index = 0; $index < 29; $index++) {
            $this->assertDomainError('QR_INVALID', fn () => app(QrService::class)->scan($player->user, $player->device,
                ['session_id' => $session->public_id, 'qr_identifier' => 'unknown-opaque-identifier'], 'unknown-scan-'.$index));
        }

        $this->assertDomainError('RATE_LIMITED', fn () => app(QrService::class)->scan($player->user, $player->device,
            ['session_id' => $session->public_id, 'qr_identifier' => 'unknown-opaque-identifier'], 'limited-scan-key'));

        $this->assertDatabaseCount('trigger_tokens', 0);
    }

    public function test_numeric_and_malformed_identifiers_are_rejected_by_the_real_api(): void
    {
        [, $session, , $player] = $this->qrFixture();
        $this->authenticateGame($player->user, $player->device);
        foreach ([123, '123', '', ['opaque']] as $identifier) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/qr/scan', ['session_id' => $session->public_id, 'qr_identifier' => $identifier])->assertUnprocessable();
        }
        $this->assertDatabaseCount('trigger_tokens', 0);
    }

    public function test_role_swap_restricts_scan_to_the_current_scanner(): void
    {
        [$admin, $session, $instance, $player, $node, , , $secret] = $this->qrFixture(['role_policy' => 'SPECIFIC_ROLE', 'role_code' => 'SCANNER']);
        $other = $instance->registration->players()->whereKeyNot($player->id)->firstOrFail();
        DB::transaction(fn () => app(RuntimeRoles::class)->release($instance, PlayerRoleAssignment::sole(), $admin));
        app(RuntimeEngine::class)->execute($admin, 'assign_role', $node->public_id,
            ['reason' => 'Scanner role', 'player_id' => $other->public_id, 'role_code' => 'SCANNER', 'slot_no' => 1], 'qr-scanner-assignment');
        $input = ['session_id' => $session->public_id, 'qr_identifier' => $secret];

        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => app(QrService::class)->scan($player->user, $player->device, $input, 'wrong-scanner-key'));
        $this->assertTrue(app(QrService::class)->scan($other->user, $other->device, $input, 'right-scanner-key')['accepted']);

        $this->assertDatabaseCount('trigger_tokens', 1);
        $this->assertSame($other->id, TriggerToken::sole()->player_id);
    }

    public function test_checkpoint_mismatch_cannot_reuse_inside_evidence(): void
    {
        [, $session, , $player, $node, , $trigger, $secret] = $this->qrFixture(checkpoint: true);
        $other = Checkpoint::factory()->create(['organization_id' => $session->organization_id]);
        DB::table('geofence_evaluations')->insert(['game_session_id' => $session->id, 'player_id' => $player->id, 'checkpoint_id' => $other->id,
            'node_instance_id' => $node->id, 'location_event_id' => 1, 'location_event_received_at' => now(), 'result' => 'INSIDE',
            'accuracy' => 3, 'evaluated_at' => now(), 'evidence_count' => 1, 'policy_hash' => str_repeat('b', 64)]);

        $this->assertDomainError('QR_OUTSIDE_ALLOWED_AREA', fn () => app(QrService::class)->scan($player->user, $player->device,
            ['session_id' => $session->public_id, 'qr_identifier' => $secret], 'mismatched-checkpoint'));

        $this->assertDatabaseCount('trigger_tokens', 0);
    }

    public function test_specific_role_fails_closed_after_the_last_assignment_is_released(): void
    {
        [$admin, $session, $instance, $player, , , , $secret] = $this->qrFixture(['role_policy' => 'SPECIFIC_ROLE', 'role_code' => 'SCANNER']);
        DB::transaction(fn () => app(RuntimeRoles::class)->release($instance, PlayerRoleAssignment::sole(), $admin));
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => app(QrService::class)->scan($player->user, $player->device,
            ['session_id' => $session->public_id, 'qr_identifier' => $secret], 'unassigned-role-scan'));
        $this->assertDatabaseCount('trigger_tokens', 0);
    }

    public function test_any_member_policy_allows_support_even_when_another_player_has_scanner_role(): void
    {
        [$admin, $session, $instance, $player, $node, , , $secret] = $this->qrFixture(['role_policy' => 'ANY_TEAM_MEMBER']);
        $other = $instance->registration->players()->whereKeyNot($player->id)->firstOrFail();
        app(RuntimeEngine::class)->execute($admin, 'assign_role', $node->public_id,
            ['reason' => 'Scanner role', 'player_id' => $other->public_id, 'role_code' => 'SCANNER', 'slot_no' => 1], 'any-member-role');
        $this->assertTrue(app(QrService::class)->scan($player->user, $player->device,
            ['session_id' => $session->public_id, 'qr_identifier' => $secret], 'support-scan')['accepted']);
        $this->assertSame($player->id, TriggerToken::sole()->player_id);
    }

    public function test_active_slot_policy_allows_assigned_decoder_and_denies_support(): void
    {
        $id = (string) Str::uuid();
        $secret = bin2hex(random_bytes(32));
        [$admin, $session, $instance, $player, $node] = $this->runtimeFixture($this->runtimeGraph('QR', ['trigger_id' => $id, 'role_policy' => 'ACTIVE_SLOT']), beforePublish: function (GameSession $session) use ($id, $secret): void {
            QrTrigger::factory()->create(['public_id' => $id, 'organization_id' => $session->organization_id, 'identifier_hash' => hash('sha256', $secret)]);
        });
        app(RuntimeEngine::class)->execute($admin, 'assign_role', $node->public_id,
            ['reason' => 'Active primary slot', 'player_id' => $player->public_id, 'role_code' => 'DECODER', 'slot_no' => 1], 'active-slot-role');
        $this->runtimeAction($player, $node, 'start_node');
        $other = $instance->registration->players()->whereKeyNot($player->id)->firstOrFail();
        $input = ['session_id' => $session->public_id, 'qr_identifier' => $secret];
        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => app(QrService::class)->scan($other->user, $other->device, $input, 'support-slot-scan'));
        $this->assertTrue(app(QrService::class)->scan($player->user, $player->device, $input, 'active-slot-scan')['accepted']);
        $this->assertSame($player->id, TriggerToken::sole()->player_id);
    }

    public function test_wrong_owned_device_cannot_scan_or_create_a_token(): void
    {
        [, $session, , $player, , , , $secret] = $this->qrFixture();
        $wrong = UserDevice::factory()->create(['user_id' => $player->user_id]);
        $this->assertDomainError('DEVICE_NOT_BOUND', fn () => app(QrService::class)->scan($player->user, $wrong,
            ['session_id' => $session->public_id, 'qr_identifier' => $secret], 'wrong-device-scan'));
        $this->assertDatabaseCount('trigger_tokens', 0);
    }

    public function test_trigger_and_runtime_checkpoint_mismatch_rejects_even_fresh_inside_evidence(): void
    {
        $id = (string) Str::uuid();
        $secret = bin2hex(random_bytes(32));
        $checkpoint = null;
        [, $session, , $player, $node] = $this->runtimeFixture(beforePublish: function (GameSession $session) use ($id, $secret, &$checkpoint): array {
            $checkpoint = Checkpoint::factory()->create(['organization_id' => $session->organization_id]);
            $other = Checkpoint::factory()->create(['organization_id' => $session->organization_id]);
            QrTrigger::factory()->create(['public_id' => $id, 'organization_id' => $session->organization_id, 'identifier_hash' => hash('sha256', $secret), 'checkpoint_id' => $checkpoint->id]);

            return $this->runtimeGraph('QR', ['trigger_id' => $id, 'checkpoint_id' => $other->public_id]);
        });
        $this->runtimeAction($player, $node, 'start_node');
        DB::table('geofence_evaluations')->insert(['game_session_id' => $session->id, 'player_id' => $player->id, 'checkpoint_id' => $checkpoint->id,
            'node_instance_id' => $node->id, 'location_event_id' => 1, 'location_event_received_at' => now(), 'result' => 'INSIDE',
            'accuracy' => 3, 'evaluated_at' => now(), 'evidence_count' => 1, 'policy_hash' => str_repeat('c', 64)]);
        $this->assertDomainError('QR_OUTSIDE_ALLOWED_AREA', fn () => app(QrService::class)->scan($player->user, $player->device,
            ['session_id' => $session->public_id, 'qr_identifier' => $secret], 'runtime-checkpoint-mismatch'));
        $this->assertDatabaseCount('trigger_tokens', 0);
    }

    public function test_specific_player_policy_rejects_other_team_members(): void
    {
        $id = (string) Str::uuid();
        $secret = bin2hex(random_bytes(32));
        [, $session, $instance, $player, $node] = $this->runtimeFixture(beforePublish: function (GameSession $session) use ($id, $secret): array {
            QrTrigger::factory()->create(['public_id' => $id, 'organization_id' => $session->organization_id, 'identifier_hash' => hash('sha256', $secret)]);

            return $this->runtimeGraph('QR', ['trigger_id' => $id, 'role_policy' => 'SPECIFIC_PLAYER', 'specific_player_id' => $session->players()->orderBy('id')->firstOrFail()->public_id]);
        });
        $other = $instance->registration->players()->whereKeyNot($player->id)->firstOrFail();
        $this->runtimeAction($player, $node, 'start_node');
        $input = ['session_id' => $session->public_id, 'qr_identifier' => $secret];

        $this->assertDomainError('ACTION_NOT_ALLOWED', fn () => app(QrService::class)->scan($other->user, $other->device, $input, 'specific-other-key'));
        $this->assertTrue(app(QrService::class)->scan($player->user, $player->device, $input, 'specific-player-key')['accepted']);

        $this->assertDatabaseCount('trigger_tokens', 1);
        $this->assertSame($player->id, TriggerToken::sole()->player_id);
    }
}

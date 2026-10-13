<?php

namespace Tests\Feature;

use App\Models\EvidenceSubmission;
use App\Models\MediaAsset;
use App\Models\OrganizationMember;
use App\Models\QrTrigger;
use App\Models\User;
use App\Services\Media\MediaService;
use App\Services\Media\SubmissionService;
use App\Services\Qr\QrService;
use App\Services\Runtime\RuntimeProjection;
use App\Services\Scenarios\GraphValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\RuntimeFixtures;
use Tests\TestCase;

class AuthoringResourcesTest extends TestCase
{
    use RefreshDatabase, RuntimeFixtures;

    public function test_location_bank_uses_postgis_and_is_tenant_scoped(): void
    {
        [$admin, $scenario] = $this->scenarioFixture(false);
        $this->scenarioAuth($admin);
        $payload = ['organization_id' => $scenario->organization->public_id, 'name' => 'Square', 'latitude' => 35.6892,
            'longitude' => 51.3890, 'default_radius_m' => 20, 'accuracy_m' => 10, 'dwell_seconds' => 5, 'category' => 'Square', 'description' => 'Meeting point', 'status' => 'ACTIVE'];
        $id = $this->postJson('/api/v1/content/locations', $payload)->assertCreated()->assertJsonPath('data.latitude', 35.6892)->json('data.id');
        $this->getJson('/api/v1/content/locations?active_only=1')->assertOk()->assertJsonCount(1, 'data');
        unset($payload['organization_id']);
        $payload['latitude'] = 36;
        $this->patchJson('/api/v1/content/locations/'.$id, $payload)->assertOk()->assertJsonPath('data.latitude', 36);
        $this->scenarioAuth(User::factory()->create());
        $this->getJson('/api/v1/content/locations/'.$id)->assertNotFound();
        $this->patchJson('/api/v1/content/locations/'.$id, $payload)->assertNotFound();
    }

    public function test_qr_bank_never_returns_secrets_and_validates_metadata(): void
    {
        [$admin, $scenario] = $this->scenarioFixture(false);
        $this->scenarioAuth($admin);
        $created = $this->postJson('/api/v1/content/qr-bank', ['organization_id' => $scenario->organization->public_id, 'name' => 'Square QR', 'label' => 'SQ1', 'status' => 'ACTIVE'])->assertCreated()->assertJsonPath('data.printable_available', true);
        $id = $created->json('data.id');
        $trigger = QrTrigger::sole();
        $secret = $trigger->printable_identity;
        $this->assertSame($trigger->identifier_hash, hash('sha256', $secret));
        $this->assertNotSame($secret, $trigger->getRawOriginal('printable_identity'));
        $this->assertStringNotContainsString($secret, $created->getContent());
        $this->assertArrayNotHasKey('printable_identity', $trigger->toArray());
        $svg = $this->get('/api/v1/content/qr-bank/'.$id.'/printable')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $svg->getContent());
        $this->assertStringContainsString('1024', $svg->getContent());
        $this->assertStringNotContainsString($secret, $svg->getContent());
        $this->assertStringNotContainsString('<script', $svg->getContent());
        $this->assertStringContainsString('no-store', $svg->headers->get('Cache-Control'));
        $response = $this->getJson('/api/v1/content/qr-bank/'.$id)->assertOk();
        $this->assertArrayNotHasKey('identifier', $response->json('data'));
        $this->assertArrayNotHasKey('identifier_hash', $response->json('data'));
        $this->patchJson('/api/v1/content/qr-bank/'.$id, ['name' => 'Square QR', 'label' => 'SQ1', 'status' => 'ACTIVE', 'notes' => 'Print near entrance'])->assertOk();
        $this->patchJson('/api/v1/content/qr-bank/'.$id, ['name' => 'Square QR', 'label' => 'SQ1', 'status' => 'ACTIVE', 'notes' => null, 'location_description' => null])->assertOk()->assertJsonPath('data.notes', '');
        $this->patchJson('/api/v1/content/qr-bank/'.$id, ['name' => 'New name', 'label' => 'SQ1', 'status' => 'INACTIVE'])->assertOk();
        $this->getJson('/api/v1/content/qr-bank/'.$id.'/printable')->assertStatus(409);
        $this->getJson('/api/v1/content/qr-bank?active_only=1')->assertOk()->assertJsonCount(0, 'data');
        $this->scenarioAuth(User::factory()->create());
        $this->getJson('/api/v1/content/qr-bank/'.$id)->assertNotFound();
        $this->getJson('/api/v1/content/qr-bank/'.$id.'/printable')->assertNotFound();
    }

    public function test_media_is_private_and_cross_tenant_graph_references_are_rejected(): void
    {
        Storage::fake('local');
        [$admin, $scenario] = $this->scenarioFixture(false);
        $created = app(MediaService::class)->create($admin, ['organization_id' => $scenario->organization->public_id, 'name' => 'Clue', 'kind' => 'FILE'], UploadedFile::fake()->createWithContent('clue.pdf', "%PDF-1.4\nA clue"));
        Storage::disk('local')->assertExists(MediaAsset::sole()->object_key);
        $this->assertArrayNotHasKey('object_key', $created);
        [, $other] = $this->scenarioFixture(false);
        $graph = $this->runtimeGraph('MediaReveal', ['media_asset_id' => $created['id'], 'require_acknowledgement' => true]);
        $this->assertSame('PASS', app(GraphValidator::class)->validate($graph, $scenario->organization_id)['status']);
        $this->assertContains('DEPENDENCY_NOT_READY', array_column(app(GraphValidator::class)->validate($graph, $other->organization_id)['errors'], 'code'));
        $this->scenarioAuth(User::factory()->create());
        $this->getJson('/api/v1/content/media/'.$created['id'].'/download')->assertNotFound();
    }

    public function test_provisioned_qr_resolves_through_the_existing_secure_scan_flow(): void
    {
        [, $session, , $player, $node] = $this->runtimeFixture(beforePublish: function ($session): array {
            $owner = User::findOrFail(OrganizationMember::where('organization_id', $session->organization_id)->firstOrFail()->user_id);
            $trigger = app(QrService::class)->provision($owner, ['organization_id' => $session->organization->public_id]);

            return $this->runtimeGraph('QR', ['trigger_id' => $trigger['id'], 'role_policy' => 'ANY_TEAM_MEMBER']);
        });
        $this->runtimeAction($player, $node, 'start_node');
        $result = app(QrService::class)->scan($player->user, $player->device, ['session_id' => $session->public_id,
            'qr_identifier' => QrTrigger::sole()->printable_identity], 'provisioned-qr-scan');
        $this->assertTrue($result['accepted']);
        $this->assertTrue($this->runtimeAction($player, $node, 'submit_action', ['qr_token' => $result['one_time_token']])['complete']);
    }

    public function test_multipart_evidence_requires_device_authorization_and_server_validated_size(): void
    {
        Storage::fake('local');
        [$admin, , , $player, $node] = $this->runtimeFixture($this->evidenceGraph());
        $this->runtimeAction($player, $node, 'start_node');
        $this->scenarioAuth($player->user);
        $url = '/api/v1/runtime/nodes/'.$node->public_id.'/evidence';
        $file = UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\nProof");
        $this->postJson($url, ['file' => $file, 'capture_source' => 'LIBRARY'], ['Idempotency-Key' => 'multipart-proof'])->assertNotFound();
        $headers = ['X-Device-Id' => $player->device->device_uuid, 'Idempotency-Key' => 'multipart-proof'];
        $this->postJson($url, ['file' => UploadedFile::fake()->create('large.pdf', 11000, 'application/pdf'), 'capture_source' => 'LIBRARY'], $headers)->assertUnprocessable();
        $id = $this->postJson($url, ['file' => $file, 'capture_source' => 'LIBRARY'], $headers)->assertCreated()->assertJsonPath('data.status', 'PENDING')->json('data.id');
        $this->get('/api/v1/submissions/'.$id.'/download')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson('/api/v1/submissions/'.$id.'/review', ['status' => 'APPROVED', 'message' => 'Self approval'])->assertNotFound();
        $this->scenarioAuth($admin);
        $this->postJson('/api/v1/submissions/'.$id.'/review', ['status' => 'APPROVED', 'message' => 'Accepted'])->assertOk();
        $this->assertSame('COMPLETED', $node->fresh()->status);
        $this->scenarioAuth(User::factory()->create());
        $this->get('/api/v1/submissions/'.$id.'/download')->assertNotFound();
    }

    public function test_review_controls_progression_and_retries_are_idempotent(): void
    {
        Storage::fake('local');
        $graph = $this->evidenceGraph();
        [$admin, , , $player, $node] = $this->runtimeFixture($graph);
        $this->runtimeAction($player, $node, 'start_node');
        $service = app(SubmissionService::class);
        $file = UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\nProof");
        $input = ['capture_source' => 'LIBRARY'];
        $submission = $service->upload($player->user, $player->device, $node->public_id, $file, $input, 'evidence-upload-1');
        $this->assertSame('PENDING', $submission['status']);
        $this->assertSame('ACTIVE', $node->fresh()->status);
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node', ['acknowledged' => true])['complete']);
        $this->assertSame($submission['id'], $service->upload($player->user, $player->device, $node->public_id, $file, $input, 'evidence-upload-1')['id']);
        $service->review($admin, $submission['id'], ['status' => 'REJECTED', 'message' => 'Try again']);
        $this->assertSame('ACTIVE', $node->fresh()->status);
        $second = $service->upload($player->user, $player->device, $node->public_id, $file, $input, 'evidence-upload-2');
        $service->review($admin, $second['id'], ['status' => 'APPROVED', 'message' => 'Accepted']);
        $this->assertSame('COMPLETED', $node->fresh()->status);
        $this->assertSame('APPROVED', $node->fresh()->state_json['outcome']);
        $this->assertSame(2, EvidenceSubmission::count());
        $this->assertSame('APPROVED', $service->review($admin, $second['id'], ['status' => 'APPROVED', 'message' => 'Accepted'])['status']);
    }

    public function test_executable_disguised_as_pdf_is_rejected_without_storage_or_progression(): void
    {
        Storage::fake('local');
        [, , , $player, $node] = $this->runtimeFixture($this->evidenceGraph());
        $this->runtimeAction($player, $node, 'start_node');
        try {
            app(SubmissionService::class)->upload($player->user, $player->device, $node->public_id, UploadedFile::fake()->createWithContent('proof.pdf', '<?php echo "unsafe";'), ['capture_source' => 'LIBRARY'], 'invalid-evidence');
            $this->fail('Executable content was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, MediaAsset::count());
            $this->assertSame('ACTIVE', $node->fresh()->status);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    private function evidenceGraph(): array
    {
        $graph = $this->runtimeGraph('FileSubmission', ['instruction' => 'Upload proof', 'max_size_mb' => 10, 'allowed_mime_types' => ['application/pdf'], 'allow_resubmit' => true, 'approval_mode' => 'GAMEMASTER_REVIEW']);
        $graph['edges'][1]['metadata'] = ['outcome' => 'APPROVED'];
        $graph['edges'][] = array_replace($graph['edges'][1], ['edge_key' => 'rejected', 'metadata' => ['outcome' => 'REJECTED']]);

        return $graph;
    }

    public function test_photo_auto_accept_advances_only_after_a_valid_image_upload(): void
    {
        Storage::fake('local');
        $graph = $this->runtimeGraph('PhotoSubmission', ['instruction' => 'Take a photo', 'max_size_mb' => 2, 'allow_resubmit' => false,
            'approval_mode' => 'AUTO_ACCEPT', 'camera_policy' => 'CAMERA_ONLY', 'require_location' => false, 'require_timestamp' => true]);
        $graph['edges'][1]['metadata'] = ['outcome' => 'APPROVED'];
        $graph['edges'][] = array_replace($graph['edges'][1], ['edge_key' => 'rejected', 'metadata' => ['outcome' => 'REJECTED']]);
        [, , , $player, $node] = $this->runtimeFixture($graph);
        $this->runtimeAction($player, $node, 'start_node');
        $file = UploadedFile::fake()->createWithContent('proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII='));
        $result = app(SubmissionService::class)->upload($player->user, $player->device, $node->public_id, $file,
            ['capture_source' => 'CAMERA', 'captured_at' => now()->toISOString()], 'photo-proof-upload');
        $this->assertSame('APPROVED', $result['status']);
        $this->assertSame('COMPLETED', $node->fresh()->status);
        $this->assertSame('IMAGE', $result['asset']['kind']);
    }

    public function test_audio_duration_is_measured_from_pcm_content(): void
    {
        Storage::fake('local');
        $graph = $this->runtimeGraph('AudioSubmission', ['instruction' => 'Record', 'max_size_mb' => 2, 'allow_resubmit' => false,
            'approval_mode' => 'GAMEMASTER_REVIEW', 'record_policy' => 'RECORD_ONLY', 'max_duration_seconds' => 1]);
        $graph['edges'][1]['metadata'] = ['outcome' => 'APPROVED'];
        $graph['edges'][] = array_replace($graph['edges'][1], ['edge_key' => 'rejected', 'metadata' => ['outcome' => 'REJECTED']]);
        [, , , $player, $node] = $this->runtimeFixture($graph);
        $this->runtimeAction($player, $node, 'start_node');
        $pcm = str_repeat("\0", 32000);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
        try {
            app(SubmissionService::class)->upload($player->user, $player->device, $node->public_id, UploadedFile::fake()->createWithContent('proof.wav', $wav), ['capture_source' => 'RECORDER'], 'long-audio-proof');
            $this->fail('An overlong recording was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, EvidenceSubmission::count());
        }
        $pcm = str_repeat("\0", 16000);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
        $result = app(SubmissionService::class)->upload($player->user, $player->device, $node->public_id, UploadedFile::fake()->createWithContent('proof.wav', $wav), ['capture_source' => 'RECORDER'], 'valid-audio-proof');
        $this->assertSame(1, $result['asset']['duration_seconds']);
        $this->assertSame('PENDING', $result['status']);
    }

    public function test_media_reveal_uses_the_immutable_library_reference(): void
    {
        Storage::fake('local');
        [, , , $player, $node] = $this->runtimeFixture(beforePublish: function ($session): array {
            $owner = User::findOrFail(OrganizationMember::where('organization_id', $session->organization_id)->firstOrFail()->user_id);
            $asset = app(MediaService::class)->create($owner, ['organization_id' => $session->organization->public_id, 'name' => 'Reveal', 'kind' => 'FILE'], UploadedFile::fake()->createWithContent('reveal.pdf', "%PDF-1.4\nClue"));

            return $this->runtimeGraph('MediaReveal', ['media_asset_id' => $asset['id'], 'require_acknowledgement' => true]);
        });
        $this->runtimeAction($player, $node, 'start_node');
        $this->assertFalse($this->runtimeAction($player, $node, 'complete_node')['complete']);
        $projection = app(RuntimeProjection::class)->node($node, player: $player);
        $this->assertSame('Reveal', $projection['config']['media']['name']);
        $this->assertArrayNotHasKey('object_key', $projection['config']['media']);
        $this->assertTrue($this->runtimeAction($player, $node, 'complete_node', ['acknowledged' => true])['complete']);
    }
}

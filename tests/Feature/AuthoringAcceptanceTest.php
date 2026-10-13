<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\OrganizationMember;
use App\Models\PlayerRoleAssignment;
use App\Models\QrTrigger;
use App\Models\User;
use App\Services\GameMaster\LiveChallengeService;
use App\Services\Media\MediaService;
use App\Services\Media\SubmissionService;
use App\Services\Qr\QrService;
use App\Services\Runtime\RuntimeEngine;
use App\Services\Scenarios\AuthoringNodeCatalog;
use App\Services\Scenarios\ScenarioGraph;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\LocationFixtures;
use Tests\TestCase;

class AuthoringAcceptanceTest extends TestCase
{
    use LocationFixtures, RefreshDatabase;

    public static function routes(): array
    {
        return ['evidence route' => [500], 'live challenge fallback' => [501]];
    }

    #[DataProvider('routes')]
    public function test_all_authoring_families_save_validate_publish_and_execute_from_canonical_payload(int $threshold): void
    {
        $this->freezeTime();
        Storage::fake('local');
        [$admin, $session, $instance, $player, $node, $version] = $this->runtimeFixture(beforePublish: function ($session) use ($threshold): array {
            $owner = User::findOrFail(OrganizationMember::where('organization_id', $session->organization_id)->firstOrFail()->user_id);
            $asset = app(MediaService::class)->create($owner, ['organization_id' => $session->organization->public_id, 'name' => 'Clue', 'kind' => 'FILE'], UploadedFile::fake()->createWithContent('clue.pdf', "%PDF-1.4\nClue"));
            $qr = app(QrService::class)->provision($owner, ['organization_id' => $session->organization->public_id, 'metadata' => ['name' => 'Square QR']]);
            $evidence = ['instruction' => 'Provide evidence', 'max_size_mb' => 10, 'allow_resubmit' => false, 'approval_mode' => 'GAMEMASTER_REVIEW'];
            $specs = [
                'start' => ['Start', []], 'task' => ['Message', ['text' => 'Begin the route']],
                'gps' => ['GPS / Location', ['latitude' => 35.6892, 'longitude' => 51.3890, 'radius_m' => 20]],
                'media' => ['MediaReveal', ['media_asset_id' => $asset['id'], 'require_acknowledgement' => true]],
                'qr' => ['QR', ['trigger_id' => $qr['id'], 'role_policy' => 'ANY_TEAM_MEMBER']],
                'puzzle' => ['Puzzle', ['prompt' => 'Code?', 'expected_answer' => 'NEXO', 'case_sensitive' => false, 'trim_whitespace' => true]],
                'set' => ['SetVariable', ['variable' => 'ready', 'value' => true]],
                'check' => ['CheckVariable', ['variable' => 'ready', 'operator' => '==', 'value' => true]],
                'random' => ['RandomPlayer', ['variable' => 'chosen', 'private_text' => 'Secret clue']],
                'score' => ['Score', ['operation' => 'ADD', 'amount' => 500, 'reason' => 'Completed route']],
                'condition' => ['Condition', ['operand' => 'score', 'operator' => '>=', 'value' => $threshold]],
                'assign' => ['AssignRole', ['roles' => ['NAVIGATOR', 'DECODER'], 'assignment_policy' => 'LEAST_RECENTLY_USED']],
                'role' => ['RoleCheck', ['role' => 'NAVIGATOR']],
                'split' => ['SplitInformation', ['minimum_player_count' => 2, 'required_roles' => ['NAVIGATOR', 'DECODER'], 'role_content' => ['NAVIGATOR' => ['text' => 'North'], 'DECODER' => ['text' => 'Code']]]],
                'together' => ['TeamTogether', ['minimum_player_count' => 2]],
                'barrier' => ['Barrier', ['minimum_player_count' => 2, 'required_variables' => ['ready']]],
                'relay' => ['Relay', ['roles' => ['NAVIGATOR', 'DECODER']]],
                'photo' => ['PhotoSubmission', $evidence + ['camera_policy' => 'CAMERA_ONLY', 'require_location' => false, 'require_timestamp' => true]],
                'audio' => ['AudioSubmission', $evidence + ['record_policy' => 'RECORD_ONLY', 'max_duration_seconds' => 30]],
                'file' => ['FileSubmission', $evidence + ['allowed_mime_types' => ['application/pdf']]],
                'time' => ['Time', ['mode' => 'DELAY', 'duration_seconds' => 30]],
                'live' => ['LiveChallenge', ['category' => 'TEAM_OBJECTIVE']], 'finish' => ['Finish', []],
            ];
            $nodes = [];
            foreach ($specs as $key => [$type, $config]) {
                $nodes[] = ['node_key' => $key, 'node_type' => $type, 'title' => $key, 'config' => $config];
            }
            $edges = [];
            $path = ['start', 'task', 'gps', 'media', 'qr', 'puzzle', 'set', 'check', 'random', 'score', 'condition', 'assign', 'role', 'split', 'together', 'barrier', 'relay', 'photo', 'audio', 'file', 'finish'];
            foreach (array_slice($path, 0, -1) as $i => $from) {
                [$type, $config] = $specs[$from];
                foreach (app(AuthoringNodeCatalog::class)->outcomes($type, $config) as $outcome) {
                    $to = $from === 'condition' && $outcome === 'FALSE' ? 'time' : $path[$i + 1];
                    $edges[] = ['edge_key' => $from.'_'.$outcome, 'from_node' => $from, 'to_node' => $to, 'priority' => count($edges), 'metadata' => ['outcome' => $outcome, 'label' => $outcome]];
                }
            }
            foreach ([['time', 'live'], ['live', 'finish']] as [$from, $to]) {
                $edges[] = ['edge_key' => $from.'_exit', 'from_node' => $from, 'to_node' => $to, 'priority' => count($edges)];
            }

            return ['nodes' => $nodes, 'edges' => $edges, 'conditions' => [], 'subflows' => [], 'variables' => [
                ['key' => 'ready', 'type' => 'BOOLEAN', 'scope' => 'TEAM', 'default_value' => false, 'writable' => true],
                ['key' => 'chosen', 'type' => 'STRING', 'scope' => 'TEAM', 'default_value' => '', 'writable' => true]]];
        });

        $this->assertSame('PUBLISHED', $version->status);
        $this->assertSame('ACTIVE', $instance->status);
        $this->assertSame('AVAILABLE', $node->status);
        $this->assertSame(23, $instance->nodes()->count());
        $snapshot = app(ScenarioGraph::class)->read($version);
        $this->assertCount(23, $snapshot['nodes']);
        try {
            app(ScenarioService::class)->execute($admin, 'saveGraph', $snapshot + ['revision_token' => $version->revision_token], $version->public_id);
            $this->fail('Published graph was mutated.');
        } catch (ApiException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertSame($snapshot, app(ScenarioGraph::class)->read($version->fresh()));

        $nodes = $instance->nodes()->with('node')->get()->keyBy(fn ($item) => $item->node->node_key);
        $this->runtimeAction($player, $node, 'start_node');
        $this->runtimeAction($player, $node, 'complete_node', ['acknowledged' => true]);
        $this->runtimeAction($player, $nodes['gps'], 'start_node');
        $this->ingest($player, $this->sample($session));
        $this->assertTrue($this->runtimeAction($player, $nodes['gps'], 'complete_node')['complete']);
        $this->runtimeAction($player, $nodes['media'], 'start_node');
        $this->runtimeAction($player, $nodes['media'], 'complete_node', ['acknowledged' => true]);
        $this->runtimeAction($player, $nodes['qr'], 'start_node');
        $scan = app(QrService::class)->scan($player->user, $player->device, ['session_id' => $session->public_id,
            'qr_identifier' => QrTrigger::sole()->printable_identity], 'acceptance-qr-scan');
        $this->assertTrue($scan['accepted']);
        $this->runtimeAction($player, $nodes['qr'], 'submit_action', ['qr_token' => $scan['one_time_token']]);
        $this->runtimeAction($player, $nodes['puzzle'], 'start_node');
        $this->runtimeAction($player, $nodes['puzzle'], 'submit_action', ['answer' => 'nexo']);
        $this->assertSame($threshold === 500 ? 'TRUE' : 'FALSE', $nodes['condition']->fresh()->state_json['outcome']);

        if ($threshold === 500) {
            $participants = [];
            foreach (PlayerRoleAssignment::whereNull('released_at')->get() as $assignment) {
                $participants[$assignment->role_code] = $instance->registration->players()->findOrFail($assignment->game_session_player_id);
            }
            foreach (['split', 'together', 'barrier', 'relay'] as $key) {
                $this->runtimeAction($participants['NAVIGATOR'], $nodes[$key], 'start_node');
                $this->assertFalse($this->runtimeAction($participants['NAVIGATOR'], $nodes[$key], 'complete_node', ['acknowledged' => true])['complete']);
                $this->assertTrue($this->runtimeAction($participants['DECODER'], $nodes[$key], 'complete_node', ['acknowledged' => true])['complete']);
            }
            $pcm = str_repeat("\0", 16000);
            $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
            $files = [
                'photo' => ['proof.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII='), 'CAMERA'],
                'audio' => ['proof.wav', $wav, 'RECORDER'],
                'file' => ['proof.pdf', "%PDF-1.4\nProof", 'LIBRARY'],
            ];
            foreach ($files as $key => [$filename, $content, $source]) {
                $this->runtimeAction($player, $nodes[$key], 'start_node');
                $submission = app(SubmissionService::class)->upload($player->user, $player->device, $nodes[$key]->public_id,
                    UploadedFile::fake()->createWithContent($filename, $content), ['capture_source' => $source, 'captured_at' => now()->toISOString()], 'acceptance-'.$key);
                $this->assertSame('PENDING', $submission['status']);
                $this->assertSame('ACTIVE', $nodes[$key]->fresh()->status);
                app(SubmissionService::class)->review($admin, $submission['id'], ['status' => 'APPROVED', 'message' => 'Verified']);
                $this->assertSame('COMPLETED', $nodes[$key]->fresh()->status);
            }
        } else {
            $this->assertSame('ACTIVE', $nodes['time']->fresh()->status);
            $this->travel(30)->seconds();
            app(RuntimeEngine::class)->expireTimers();
            $this->runtimeAction($player, $nodes['live'], 'start_node');
            $service = app(LiveChallengeService::class);
            $challenge = $service->create($admin, $session->public_id, ['type' => 'TEAM_OBJECTIVE', 'objective' => 'Complete the task',
                'targets' => [['type' => 'TEAM', 'id' => $instance->registration->team->public_id]]], 'acceptance-live');
            $service->transition($player->user, $challenge['id'], 'start', [], 'acceptance-live-start');
            $service->transition($player->user, $challenge['id'], 'submit', ['acknowledged' => true], 'acceptance-live-submit');
            $this->assertTrue($this->runtimeAction($player, $nodes['live'], 'complete_node', ['acknowledged' => true])['complete']);
        }
        $this->runtimeAction($player, $nodes['finish'], 'start_node');
        $this->runtimeAction($player, $nodes['finish'], 'complete_node', ['acknowledged' => true]);
        $this->assertSame('COMPLETED', $instance->fresh()->status);
    }
}

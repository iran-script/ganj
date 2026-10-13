<?php

namespace App\Services\Media;

use App\Exceptions\ApiException;
use App\Models\EvidenceSubmission;
use App\Models\GameSessionPlayer;
use App\Models\RuntimeTimer;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Audit\AuditService;
use App\Services\Games\GameScope;
use App\Services\Outbox\OutboxService;
use App\Services\Runtime\AuthoringRuntime;
use App\Services\Runtime\RuntimeEngine;
use App\Services\Runtime\RuntimeRoles;
use App\Services\Runtime\RuntimeTimers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SubmissionService
{
    public function __construct(private GameScope $scope, private MediaService $media, private AuditService $audit, private OutboxService $outbox) {}

    public function upload(User $actor, UserDevice $device, string $nodeId, UploadedFile $file, array $input, string $key): array
    {
        Validator::make($input + ['key' => $key], ['key' => 'required|string|min:8|max:255', 'captured_at' => 'sometimes|date', 'capture_source' => 'required|in:CAMERA,RECORDER,LIBRARY'])->validate();
        $asset = null;
        try {
            return DB::transaction(function () use ($actor, $device, $nodeId, $file, $input, $key, &$asset): array {
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $actor->ensureActive();
                [$node, $player] = $this->playerNode($actor, $device, $nodeId, true);
                $hash = hash('sha256', json_encode([$nodeId, $device->public_id, hash_file('sha256', $file->getRealPath()), $input]));
                $idempotencyKey = hash('sha256', $actor->public_id.':'.$key);
                $existing = EvidenceSubmission::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    if ($existing->request_hash !== $hash) {
                        throw new ApiException('IDEMPOTENCY_CONFLICT', 'The upload key belongs to another request.', [], 409);
                    }

                    return $this->project($existing);
                }
                $this->assertOpen($node);
                $config = $node->node->config;
                $kind = match ($node->node->node_type) {
                    'PhotoSubmission' => 'IMAGE', 'AudioSubmission' => 'AUDIO', 'FileSubmission' => 'FILE', default => null
                };
                if (! $kind) {
                    throw new ApiException('ACTION_NOT_ALLOWED', 'This node does not accept uploads.', [], 409);
                }
                $previous = EvidenceSubmission::where('team_node_instance_id', $node->id)->latest('id')->first();
                if ($previous && ($previous->status !== 'REJECTED' || ! $config['allow_resubmit'])) {
                    throw new ApiException('STATE_TRANSITION_INVALID', 'This node already has a submission.', [], 409);
                }
                if (($config['camera_policy'] ?? '') === 'CAMERA_ONLY' && $input['capture_source'] !== 'CAMERA'
                    || ($config['record_policy'] ?? '') === 'RECORD_ONLY' && $input['capture_source'] !== 'RECORDER') {
                    throw new ApiException('VALIDATION_FAILED', 'The capture policy requires a new recording.', [], 422);
                }
                if ($config['require_timestamp'] ?? false) {
                    Validator::make($input, ['captured_at' => 'required|date|after_or_equal:'.$node->started_at->toISOString().'|before_or_equal:'.now()->addSeconds(30)->toISOString()])->validate();
                }
                $locationVerified = DB::table('player_latest_locations')->where('game_session_player_id', $player->id)->where('eligible', true)->where('server_received_at', '>=', now()->subSeconds(60))->exists();
                if (($config['require_location'] ?? false) && ! $locationVerified) {
                    throw new ApiException('LOCATION_INVALID', 'A fresh measured location is required.', [], 422);
                }
                $asset = $this->media->store($actor, $node->instance->session->organization_id, $file, $node->node->title ?? $node->node->node_key,
                    $kind, 'EVIDENCE', $config['max_size_mb'], $config['allowed_mime_types'] ?? null, $kind === 'AUDIO' ? $config['max_duration_seconds'] : null);
                $submission = new EvidenceSubmission;
                $submission->forceFill(['organization_id' => $asset->organization_id, 'media_asset_id' => $asset->id, 'game_session_id' => $node->instance->game_session_id,
                    'team_node_instance_id' => $node->id, 'game_session_player_id' => $player->id, 'status' => $config['approval_mode'] === 'AUTO_ACCEPT' ? 'APPROVED' : 'PENDING',
                    'idempotency_key' => $idempotencyKey, 'request_hash' => $hash, 'location_verified' => $locationVerified, 'captured_at' => $input['captured_at'] ?? null])->save();
                $node->forceFill(['attempt_count' => $node->attempt_count + 1])->save();
                $this->event($submission, $actor, 'submitted');
                if ($submission->status === 'APPROVED') {
                    app(AuthoringRuntime::class)->outcome($node, 'APPROVED');
                    app(RuntimeEngine::class)->terminal($node->instance, $node, 'COMPLETED', $actor, $player);
                }

                return $this->project($submission);
            });
        } catch (\Throwable $error) {
            if ($asset) {
                Storage::disk($asset->disk)->delete($asset->object_key);
            }
            throw $error;
        }
    }

    /** Player access is bound to the frozen roster and current active device. */
    public function playerNode(User $actor, UserDevice $device, string $id, bool $lock = false): array
    {
        $node = Str::isUuid($id) ? TeamNodeInstance::where('public_id', $id)->first() : null;
        if (! $node) {
            throw $this->notFound();
        }
        $session = $this->scope->resolve($actor, $node->instance->session->public_id, true, $lock);
        if ($lock) {
            $instance = TeamScenarioInstance::whereKey($node->team_scenario_instance_id)->lockForUpdate()->sole();
            $node = TeamNodeInstance::whereKey($node->id)->lockForUpdate()->sole()->setRelation('instance', $instance);
        }
        $player = $session->players()->where('game_session_team_id', $node->instance->game_session_team_id)->where('user_id', $actor->id)->where('participation_status', 'ACTIVE')->first();
        if (! $player || $player->active_device_id !== $device->id || $device->user_id !== $actor->id || $device->revoked_at || ! $player->roster_frozen_at || $node->status === 'LOCKED') {
            throw $this->notFound();
        }
        app(RuntimeRoles::class)->eligible($node->instance, $node, $player, $device);

        return [$node, $player];
    }

    private function assertOpen(TeamNodeInstance $node): void
    {
        if ($node->status !== 'ACTIVE' || $node->instance->status !== 'ACTIVE' || $node->instance->session->status !== 'RUNNING') {
            throw new ApiException('STATE_TRANSITION_INVALID', 'The node is not accepting evidence.', [], 409);
        }
        $timer = RuntimeTimer::where('node_instance_id', $node->id)->first();
        if ($timer && $timer->duration_seconds !== null && app(RuntimeTimers::class)->elapsed($timer) >= $timer->duration_seconds) {
            throw new ApiException('STATE_TRANSITION_INVALID', 'The evidence deadline has passed.', [], 409);
        }
    }

    public function review(User $actor, string $id, array $input): array
    {
        Validator::make($input, ['status' => 'required|in:APPROVED,REJECTED', 'message' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $id, $input): array {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $submission = Str::isUuid($id) ? EvidenceSubmission::where('public_id', $id)->first() : null;
            if (! $submission) {
                throw $this->notFound();
            }
            $session = $this->scope->resolve($actor, $submission->session->public_id, true, true);
            if (! $this->scope->operator($actor, $session)) {
                throw $this->notFound();
            }
            $instance = TeamScenarioInstance::whereKey($submission->node->team_scenario_instance_id)->lockForUpdate()->sole();
            $node = TeamNodeInstance::whereKey($submission->team_node_instance_id)->lockForUpdate()->sole()->setRelation('instance', $instance);
            $submission = EvidenceSubmission::whereKey($submission->id)->lockForUpdate()->sole();
            if ($submission->status !== 'PENDING') {
                if ($submission->status === $input['status']) {
                    return $this->project($submission);
                }
                throw new ApiException('CONFLICT', 'This review has already been finalized.', [], 409);
            }
            $this->assertOpen($node);
            $submission->forceFill(['status' => $input['status'], 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_message' => $input['message']])->save();
            $this->event($submission, $actor, strtolower($input['status']));
            if ($input['status'] === 'APPROVED' || ! $node->node->config['allow_resubmit']) {
                app(AuthoringRuntime::class)->outcome($node, $input['status']);
                app(RuntimeEngine::class)->terminal($instance, $node, $input['status'] === 'APPROVED' ? 'COMPLETED' : 'FAILED', $actor, $submission->player);
            }

            return $this->project($submission);
        });
    }

    public function complete(TeamNodeInstance $node, ?GameSessionPlayer $player, array $input): array
    {
        return ['accepted' => true, 'complete' => false, 'result' => 'WAITING_FOR_EVIDENCE_REVIEW'];
    }

    public function visible(User $actor, string $id): EvidenceSubmission
    {
        $submission = Str::isUuid($id) ? EvidenceSubmission::where('public_id', $id)->first() : null;
        if (! $submission) {
            throw $this->notFound();
        }
        $session = $this->scope->resolve($actor, $submission->session->public_id, true);
        if ($submission->player->user_id !== $actor->id && ! $this->scope->operator($actor, $session)) {
            throw $this->notFound();
        }

        return $submission;
    }

    public function index(User $actor, string $sessionId): array
    {
        $session = $this->scope->resolve($actor, $sessionId, true);
        if (! $this->scope->operator($actor, $session)) {
            throw $this->notFound();
        }

        return EvidenceSubmission::where('game_session_id', $session->id)->latest()->limit(500)->get()->map(fn ($submission) => $this->project($submission))->all();
    }

    public function project(EvidenceSubmission $submission): array
    {
        return ['id' => $submission->public_id, 'session_id' => $submission->session->public_id, 'team_id' => $submission->player->registration->team->public_id,
            'player_id' => $submission->player->public_id, 'node_id' => $submission->node->public_id, 'node_title' => $submission->node->node->title,
            'type' => $submission->node->node->node_type, 'status' => $submission->status, 'asset' => $this->media->project($submission->asset),
            'location_verified' => $submission->location_verified, 'submitted_at' => $submission->created_at->toISOString(), 'review_message' => $submission->review_message];
    }

    private function event(EvidenceSubmission $submission, User $actor, string $action): void
    {
        $this->audit->record('evidence.'.$action, 'evidence_submission', $submission->public_id, $submission->review_message, ['node_id' => $submission->node->public_id], $actor->id, $submission->organization_id);
        $this->outbox->record('evidence.'.$action.'.v1', 'runtime', 'evidence_submission', $submission->public_id,
            ['submission_id' => $submission->public_id, 'node_id' => $submission->node->public_id, 'status' => $submission->status]);
    }

    private function notFound(): ApiException
    {
        return new ApiException('RESOURCE_NOT_FOUND', 'Evidence not found.', [], 404);
    }
}

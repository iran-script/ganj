<?php

namespace App\Services\Qr;

use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\QrTrigger;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Audit\AuditService;
use App\Services\Games\GameScope;
use App\Services\Idempotency\IdempotencyStore;
use App\Services\Runtime\RuntimeRoles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class QrService
{
    public function __construct(private GameScope $scope, private IdempotencyStore $idempotency, private QrEvents $events, private RuntimeRoles $roles, private AuditService $audit) {}

    /** @return array{trigger:array,identifier:string} */
    public function create(User $actor, array $input): array
    {
        Validator::make($input, ['organization_id' => 'required|uuid', 'checkpoint_id' => 'sometimes|nullable|uuid', 'metadata' => 'sometimes|array'])->validate();
        $organization = $this->scope->administrator($actor, (int) DB::table('organizations')->where('public_id', $input['organization_id'])->value('id'))
            ? DB::table('organizations')->where('public_id', $input['organization_id'])->first() : null;
        if (! $organization) {
            throw new ApiException('AUTHORIZATION_DENIED', 'QR trigger management is restricted to Organization administrators.', [], 403);
        }
        $checkpointId = null;
        if (isset($input['checkpoint_id'])) {
            $checkpointId = DB::table('checkpoints')->where('public_id', $input['checkpoint_id'])->where('organization_id', $organization->id)->value('id');
            if (! $checkpointId) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'The requested checkpoint was not found.', [], 404);
            }
        }
        $raw = $this->randomSecret();
        $trigger = new QrTrigger;
        $trigger->forceFill(['organization_id' => $organization->id, 'identifier_hash' => hash('sha256', $raw), 'checkpoint_id' => $checkpointId,
            'status' => 'ACTIVE', 'metadata' => $input['metadata'] ?? []])->save();

        return ['trigger' => ['id' => $trigger->public_id, 'status' => $trigger->status, 'metadata' => $trigger->metadata], 'identifier' => $raw];
    }

    public function lifecycle(User $actor, string $triggerId, string $status): array
    {
        return DB::transaction(function () use ($actor, $triggerId, $status): array {
            $trigger = QrTrigger::where('public_id', $triggerId)->lockForUpdate()->first();
            if (! $trigger || ! $this->scope->administrator($actor, $trigger->organization_id)) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'The requested QR trigger was not found.', [], 404);
            }
            $previous = $trigger->status;
            $trigger->forceFill(['status' => $status])->save();
            if ($status !== 'ACTIVE') {
                DB::table('trigger_tokens')->where('qr_trigger_id', $trigger->id)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
            $this->audit->record('qr.trigger_'.$status.'.v1', 'qr_trigger', $trigger->public_id, null,
                ['from_status' => $previous, 'to_status' => $status], $actor->id, $trigger->organization_id);

            return ['id' => $trigger->public_id, 'status' => $trigger->status, 'metadata' => $trigger->metadata];
        });
    }

    public function scan(User $actor, UserDevice $device, array $input, string $idempotencyKey): array
    {
        $result = DB::transaction(fn (): array => $this->scanLocked($actor, $device, $input, $idempotencyKey));
        if (isset($result['error'])) {
            throw new ApiException($result['error']['code'], $result['error']['message'], [], $result['error']['status']);
        }

        return $result;
    }

    private function scanLocked(User $actor, UserDevice $device, array $input, string $idempotencyKey): array
    {
        Validator::make($input, ['session_id' => 'required|uuid', 'qr_identifier' => 'required|string|min:16|max:512', 'location_event_id' => 'sometimes|nullable|integer'])->validate();
        Validator::make(['key' => $idempotencyKey], ['key' => 'required|string|min:8|max:255'])->validate();
        $fingerprint = hash('sha256', json_encode(['actor' => $actor->public_id, 'device' => $device->public_id, 'input' => $input], JSON_THROW_ON_ERROR));
        User::whereKey($actor->id)->lockForUpdate()->firstOrFail()->ensureActive();
        $session = $this->scope->resolve($actor, $input['session_id'], true, true);
        $participant = GameSessionPlayer::where('game_session_id', $session->id)->where('user_id', $actor->id)->lockForUpdate()->first();
        $bound = UserDevice::whereKey($device->id)->where('user_id', $actor->id)->whereNull('revoked_at')->lockForUpdate()->first();
        if (! $participant || in_array($participant->participation_status, ['REMOVED', 'DISQUALIFIED'], true)) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'The Session participation was not found.', [], 404);
        }
        if (! $bound || $participant->active_device_id !== $bound->id || $participant->roster_frozen_at === null) {
            return $this->reject($participant, null, null, 'DEVICE_NOT_BOUND', 'DEVICE_NOT_BOUND');
        }
        $key = 'qr:'.hash('sha256', $idempotencyKey);
        $reserved = $this->idempotency->reserve($key, $fingerprint);
        if ($reserved['status'] === 'completed') {
            $saved = $reserved['response']['data'];

            return $saved;
        }
        if ($reserved['status'] !== 'reserved') {
            throw new ApiException('CONFLICT', 'The QR request is still processing.', [], 409);
        }
        try {
            $result = DB::transaction(function () use ($actor, $device, $input): array {
                $session = $this->scope->resolve($actor, $input['session_id'], true, true);
                $player = GameSessionPlayer::where('game_session_id', $session->id)->where('user_id', $actor->id)->lockForUpdate()->first();
                if (! $player || in_array($player->participation_status, ['REMOVED', 'DISQUALIFIED'], true)) {
                    throw new ApiException('RESOURCE_NOT_FOUND', 'The Session participation was not found.', [], 404);
                }
                if ($player->active_device_id !== $device->id) {
                    return $this->reject($player, null, null, 'DEVICE_NOT_BOUND', 'DEVICE_NOT_BOUND');
                }
                if ($session->status !== 'RUNNING') {
                    return $this->reject($player, null, null, 'SESSION_NOT_RUNNING', 'ACTION_NOT_ALLOWED');
                }
                $instance = TeamScenarioInstance::where('game_session_team_id', $player->game_session_team_id)->where('status', 'ACTIVE')->lockForUpdate()->first();
                $node = $instance?->nodes()->where('status', 'ACTIVE')->whereHas('node', fn ($query) => $query->where('node_type', 'QR'))->with('node')->lockForUpdate()->first();
                if (! $instance || ! $node) {
                    return $this->reject($player, null, null, 'NODE_NOT_ACTIVE', 'NODE_NOT_ACTIVE');
                }
                $trigger = QrTrigger::where('identifier_hash', hash('sha256', $input['qr_identifier']))->lockForUpdate()->first();
                if (! $trigger || $trigger->organization_id !== $session->organization_id || $trigger->status !== 'ACTIVE') {
                    $this->checkRateLimit($actor, $device, $session->public_id, true);

                    return $this->reject($player, $node, null, 'INVALID_IDENTIFIER', 'QR_INVALID');
                }
                $allowed = array_values(array_filter([$node->node->config['trigger_id'] ?? null, $node->node->config['trigger_public_id'] ?? null, $node->node->config['qr_trigger_id'] ?? null]));
                $allowed = [...$allowed, ...($node->node->config['allowed_trigger_ids'] ?? [])];
                if ($allowed === [] || ! in_array($trigger->public_id, $allowed, true)) {
                    $this->checkRateLimit($actor, $device, $session->public_id, false);

                    return $this->reject($player, $node, $trigger, 'NODE_NOT_ACTIVE', 'ACTION_NOT_ALLOWED');
                }
                try {
                    $this->roles->eligible($instance, $node, $player, $device);
                } catch (ApiException $exception) {
                    return $this->reject($player, $node, $trigger, 'WRONG_PLAYER_ROLE', $exception->errorCode === 'ACTION_NOT_ALLOWED' ? 'ACTION_NOT_ALLOWED' : $exception->errorCode);
                }
                $policy = $node->node->config['role_policy'] ?? 'ANY_TEAM_MEMBER';
                if ($policy === 'SPECIFIC_PLAYER' && ($node->state_json['selected_player_id'] ?? $node->node->config['specific_player_id'] ?? null) !== $player->public_id) {
                    return $this->reject($player, $node, $trigger, 'WRONG_PLAYER_ROLE', 'ACTION_NOT_ALLOWED');
                }
                if ($trigger->checkpoint_id !== null || isset($node->node->config['checkpoint_id'])) {
                    $nodeCheckpointId = null;
                    if (isset($node->node->config['checkpoint_id'])) {
                        $nodeCheckpointId = DB::table('checkpoints')
                            ->where('organization_id', $session->organization_id)
                            ->where('public_id', $node->node->config['checkpoint_id'])
                            ->value('id');
                    }
                    $checkpointIds = array_values(array_unique(array_filter([$trigger->checkpoint_id, $nodeCheckpointId])));
                    $evaluation = DB::table('geofence_evaluations')->where('player_id', $player->id)->where('node_instance_id', $node->id)->latest('id')->first();
                    $maxAge = (int) ($node->node->config['max_location_age_seconds'] ?? config('location.fresh_seconds'));
                    if (! $evaluation || $evaluation->result !== 'INSIDE' || Carbon::parse($evaluation->evaluated_at)->diffInSeconds(now()) > $maxAge || ($evaluation && Carbon::parse($evaluation->evaluated_at)->isFuture())
                        || count($checkpointIds) !== 1 || $evaluation->checkpoint_id !== $checkpointIds[0]) {
                        return $this->reject($player, $node, $trigger, 'OUTSIDE_ALLOWED_AREA', 'QR_OUTSIDE_ALLOWED_AREA');
                    }
                }
                $this->checkRateLimit($actor, $device, $session->public_id, false);
                $token = $this->randomSecret();
                $expires = now()->addSeconds((int) ($node->node->config['token_ttl_seconds'] ?? 60));
                DB::table('trigger_tokens')->insert(['token_hash' => hash('sha256', $token), 'game_session_id' => $session->id,
                    'team_id' => $player->registration->team_id, 'player_id' => $player->id, 'device_id' => $device->id, 'node_instance_id' => $node->id,
                    'qr_trigger_id' => $trigger->id, 'trigger_type' => 'QR', 'expires_at' => $expires, 'created_at' => now()]);
                $this->events->record('qr.scanned.v1', $player, ['trigger_id' => $trigger->public_id, 'node_instance_id' => $node->public_id]);
                $this->events->record('qr.trigger_resolved.v1', $player, ['trigger_id' => $trigger->public_id, 'node_instance_id' => $node->public_id]);
                $this->events->record('qr.token_issued.v1', $player, ['trigger_id' => $trigger->public_id, 'node_instance_id' => $node->public_id, 'expires_at' => $expires->toISOString()]);
                DB::table('qr_scan_events')->insert(['game_session_id' => $session->id, 'team_id' => $player->registration->team_id, 'player_id' => $player->id,
                    'qr_trigger_id' => $trigger->id, 'node_instance_id' => $node->id, 'location_event_id' => $input['location_event_id'] ?? null,
                    'result' => 'TOKEN_ISSUED', 'occurred_at' => now()]);

                return ['accepted' => true, 'trigger_type' => $trigger->metadata['trigger_type'] ?? 'QR', 'action_reference' => $trigger->metadata['action_reference'] ?? null,
                    'content' => $trigger->metadata['content'] ?? null, 'one_time_token' => $token, 'expires_at' => $expires->toISOString(), 'online_required' => true];
            });
        } catch (ApiException $exception) {
            $response = ['data' => ['error' => ['code' => $exception->errorCode, 'message' => 'The QR scan was rejected.', 'status' => $exception->status]]];
            $this->idempotency->complete($key, $fingerprint, $exception->status, $response);

            return $response['data'];
        }
        $response = ['data' => $result];
        $this->idempotency->complete($key, $fingerprint, 200, $response);

        return $result;
    }

    private function reject(GameSessionPlayer $player, ?TeamNodeInstance $node, ?QrTrigger $trigger, string $reason, string $code): array
    {
        DB::table('qr_scan_events')->insert(['game_session_id' => $player->game_session_id, 'team_id' => $player->registration->team_id, 'player_id' => $player->id,
            'qr_trigger_id' => $trigger?->id, 'node_instance_id' => $node?->id, 'result' => $reason === 'REPLAY' ? 'REPLAY_DETECTED' : 'REJECTED', 'reason_code' => $reason, 'occurred_at' => now()]);
        $this->events->record('qr.scan_rejected.v1', $player, ['reason_code' => $reason, 'node_instance_id' => $node?->public_id]);

        return ['accepted' => false, 'error' => ['code' => $code, 'message' => 'The QR scan was rejected.', 'status' => $code === 'DEVICE_NOT_BOUND' ? 403 : 409]];
    }

    private function checkRateLimit(User $actor, UserDevice $device, string $sessionId, bool $unknownSensitive): void
    {
        $key = 'qr:'.hash('sha256', $actor->id.':'.$device->id.':'.$sessionId.':'.($unknownSensitive ? 'unknown' : 'scan'));
        if (RateLimiter::tooManyAttempts($key, $unknownSensitive ? 30 : 120)) {
            throw new ApiException('RATE_LIMITED', 'QR scan rate limit exceeded.', ['retry_after_seconds' => RateLimiter::availableIn($key)], 429);
        }
        RateLimiter::hit($key, 60);
    }

    private function randomSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}

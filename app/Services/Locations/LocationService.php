<?php

namespace App\Services\Locations;

use App\Exceptions\ApiException;
use App\Models\GameSession;
use App\Models\GameSessionPlayer;
use App\Models\User;
use App\Models\UserDevice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LocationService
{
    public function __construct(private LocationInput $input, private GeofenceVerifier $verifier) {}

    public function player(User $actor, string $sessionId, ?string $deviceUuid, bool $active = true): GameSessionPlayer
    {
        $session = Str::isUuid($sessionId) ? GameSession::where('public_id', $sessionId)->lockForUpdate()->first() : null;
        $player = $session?->players()->where('user_id', $actor->id)->first();
        if (! $player) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Session participation not found.', [], 404);
        }
        $device = Str::isUuid($deviceUuid ?? '') ? UserDevice::where('user_id', $actor->id)->where('device_uuid', strtolower($deviceUuid))->lockForUpdate()->first() : null;
        if (! $device || $device->revoked_at !== null || $player->active_device_id !== $device->id) {
            throw new ApiException('DEVICE_NOT_BOUND', 'This device is not bound to this Session Player.', [], 403);
        }
        if ($active && (! in_array($session->status, ['RUNNING', 'PAUSED'], true)
            || ! in_array($player->participation_status, ['ACTIVE', 'DISCONNECTED'], true)
            || ! in_array($player->registration->status, ['ACTIVE', 'PAUSED'], true))) {
            throw new ApiException('ACTION_NOT_ALLOWED', 'Gameplay location collection is not active.', [], 409);
        }

        return $player->setRelation('session', $session)->setRelation('device', $device);
    }

    public function ingest(User $actor, ?string $deviceUuid, array $input): array
    {
        $sample = $this->input->sample($input);

        return DB::transaction(function () use ($actor, $deviceUuid, $sample): array {
            $actor = User::whereKey($actor->id)->lockForUpdate()->sole();
            $actor->ensureActive();
            $player = $this->player($actor, $sample['session_id'], $deviceUuid);
            $deviceId = $player->active_device_id;
            $canonical = $sample;
            ksort($canonical);
            $hash = hash('sha256', json_encode($canonical, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            $duplicate = DB::table('location_events')->where('game_session_player_id', $player->id)->where('device_id', $deviceId)
                ->where('sequence', $sample['sequence'])->first();
            if ($duplicate) {
                if ((json_decode($duplicate->metadata, true)['request_hash'] ?? null) !== $hash) {
                    throw new ApiException('IDEMPOTENCY_CONFLICT', 'This sequence belongs to a different observation.', [], 409);
                }

                return ['accepted' => true, 'server_received_at' => CarbonImmutable::parse($duplicate->server_received_at)->toISOString()];
            }
            $now = now()->utc()->startOfSecond();
            $latest = DB::table('player_latest_locations')->where('game_session_player_id', $player->id)->first();
            $sequences = $latest ? json_decode($latest->stream_sequences, true) : [];
            $high = $sequences[(string) $deviceId] ?? -1;
            $timestamp = CarbonImmutable::parse($sample['client_timestamp']);
            $late = $sample['sequence'] <= $high || $timestamp->lessThan($now->copy()->subSeconds(config('location.fresh_seconds')))
                || ($latest && $latest->device_id === $deviceId && $timestamp->lessThanOrEqualTo(CarbonImmutable::parse($latest->client_timestamp)));
            if ($timestamp->lessThan($now->copy()->subDays(config('location.retention_days')))) {
                throw new ApiException('LOCATION_INVALID', 'Observation is outside the retained replay window.', [], 422);
            }
            $geom = 'ST_SetSRID(ST_MakePoint(?,?),4326)';
            $metadata = ['request_hash' => $hash, 'event_type' => 'location.sample_accepted.v1',
                'request_id' => request()->attributes->get('request_id'), 'sequence_gap' => $high >= 0 && $sample['sequence'] > $high + 1];
            $event = DB::selectOne("INSERT INTO location_events(public_id,game_session_id,game_session_player_id,device_id,geom,accuracy,altitude,speed,heading,
                client_timestamp,server_received_at,sequence,is_late,metadata) VALUES(?,?,?,?,$geom,?,?,?,?,?,?,?,?,?::jsonb) RETURNING id",
                [(string) Str::uuid(), $player->game_session_id, $player->id, $deviceId, $sample['lng'], $sample['lat'], $sample['accuracy'],
                    $sample['altitude'], $sample['speed'], $sample['heading'], $sample['client_timestamp'], $now, $sample['sequence'], $late, json_encode($metadata)]);
            if ($sample['sequence'] > $high && (! $late || ! $latest)) {
                $sequences[(string) $deviceId] = $sample['sequence'];
                DB::statement("INSERT INTO player_latest_locations(game_session_player_id,device_id,geom,accuracy,client_timestamp,server_received_at,sequence,
                    location_event_id,location_event_received_at,eligible,stream_sequences) VALUES(?,?,$geom,?,?,?,?,?,?,?,?::jsonb)
                    ON CONFLICT(game_session_player_id) DO UPDATE SET device_id=excluded.device_id,geom=excluded.geom,accuracy=excluded.accuracy,
                    client_timestamp=excluded.client_timestamp,server_received_at=excluded.server_received_at,sequence=excluded.sequence,
                    location_event_id=excluded.location_event_id,location_event_received_at=excluded.location_event_received_at,
                    eligible=excluded.eligible,stream_sequences=excluded.stream_sequences",
                    [$player->id, $deviceId, $sample['lng'], $sample['lat'], $sample['accuracy'], $sample['client_timestamp'], $now,
                        $sample['sequence'], $event->id, $now, ! $late && $player->session->status === 'RUNNING', json_encode($sequences)]);
                $this->verifier->observe($player, DB::table('player_latest_locations')->where('game_session_player_id', $player->id)->sole());
            }

            if ($late && $latest && $sample['sequence'] > $high) {
                $sequences[(string) $deviceId] = $sample['sequence'];
                DB::table('player_latest_locations')->where('game_session_player_id', $player->id)->update(['stream_sequences' => json_encode($sequences)]);
            }

            return ['accepted' => true, 'server_received_at' => $now->toISOString()];
        }, 3);
    }

    /** Validate every sample; deterministic ordered, all-or-nothing batch transaction. */
    public function batch(User $actor, ?string $deviceUuid, array $input): array
    {
        $this->input->validate($input, ['samples' => 'required|array|list|min:1|max:'.config('location.batch_size'), 'samples.*' => 'required|array']);
        $samples = array_map(fn (array $sample): array => $this->input->sample($sample), $input['samples']);
        if (count(array_unique(array_column($samples, 'session_id'))) !== 1) {
            throw new ApiException('LOCATION_INVALID', 'A batch must belong to one Session.', [], 422);
        }
        usort($samples, fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        return DB::transaction(function () use ($actor, $deviceUuid, $samples): array {
            $actor = User::whereKey($actor->id)->lockForUpdate()->sole();
            $actor->ensureActive();
            $player = $this->player($actor, $samples[0]['session_id'], $deviceUuid);
            $existing = DB::table('location_events')->where('game_session_player_id', $player->id)->where('device_id', $player->active_device_id)
                ->whereIn('sequence', array_column($samples, 'sequence'))->count();
            $results = array_map(fn (array $sample): array => $this->ingest($actor, $deviceUuid, $sample), $samples);
            if ($existing < count(array_unique(array_column($samples, 'sequence')))) {
                app(LocationEvents::class)->record('batch_accepted', $player, ['sample_count' => count($samples)]);
            }

            return ['samples' => $results];
        }, 3);
    }

    public function policy(User $actor, string $sessionId, ?string $deviceUuid): array
    {
        return DB::transaction(function () use ($actor, $sessionId, $deviceUuid): array {
            $player = $this->player($actor, $sessionId, $deviceUuid, false);
            $active = in_array($player->session->status, ['RUNNING', 'PAUSED'], true)
                && in_array($player->participation_status, ['ACTIVE', 'DISCONNECTED'], true)
                && in_array($player->registration->status, ['ACTIVE', 'PAUSED'], true);

            return ['active' => $active, 'interval_seconds' => config('location.interval_seconds'),
                'near_checkpoint_interval' => config('location.near_checkpoint_interval'), 'stationary_interval' => config('location.stationary_interval'),
                'min_accuracy' => config('location.min_accuracy'), 'background_tracking_required' => $active, 'policy_version' => config('location.policy_version')];
        });
    }
}

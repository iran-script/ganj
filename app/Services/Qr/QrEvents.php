<?php

namespace App\Services\Qr;

use App\Models\GameSessionPlayer;
use App\Services\Outbox\OutboxService;
use Illuminate\Support\Str;

class QrEvents
{
    public const TYPES = ['qr.scanned.v1', 'qr.scan_rejected.v1', 'qr.trigger_resolved.v1', 'qr.token_issued.v1', 'qr.token_consumed.v1', 'qr.token_replay_detected.v1'];

    public function record(string $type, GameSessionPlayer $player, array $data): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \LogicException('Unknown QR event.');
        }
        $eventId = (string) Str::uuid();
        $session = $player->session;
        $payload = ['event_id' => $eventId, 'event_type' => $type, 'payload_version' => 1, 'occurred_at' => now()->toISOString(),
            'producer' => 'qr', 'organization_id' => $session->organization->public_id, 'session_id' => $session->public_id,
            'player_id' => $player->public_id, 'request_id' => request()->attributes->get('request_id'), 'data' => $data];
        app(OutboxService::class)->record($type, 'qr', 'game_session_player', $player->public_id, $payload, 1, $eventId);
    }
}

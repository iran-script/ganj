<?php

namespace App\Services\Locations;

use App\Models\GameSessionPlayer;
use App\Services\Outbox\OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LocationEvents
{
    public function record(string $action, GameSessionPlayer $player, array $data): void
    {
        if (DB::transactionLevel() === 0 || ! in_array($action, ['geofence_entered', 'geofence_exited', 'geofence_uncertain', 'checkpoint_verified', 'checkpoint_rejected', 'batch_accepted', 'policy_changed'], true)) {
            throw new \LogicException('Derived location events require a transaction and canonical name.');
        }
        $eventId = (string) Str::uuid();
        $session = $player->session;
        $type = 'location.'.$action.'.v1';
        $payload = ['event_id' => $eventId, 'event_type' => $type, 'payload_version' => 1, 'occurred_at' => now()->toISOString(),
            'producer' => 'location', 'organization_id' => $session->organization->public_id, 'session_id' => $session->public_id,
            'player_id' => $player->public_id, 'actor' => ['type' => 'user', 'id' => $player->user->public_id],
            'request_id' => request()->attributes->get('request_id'), 'data' => $data];
        app(OutboxService::class)->record($type, 'location', 'game_session_player', $player->public_id, $payload, 1, $eventId);
    }
}

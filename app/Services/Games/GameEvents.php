<?php

namespace App\Services\Games;

use App\Models\Game;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Outbox\OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GameEvents
{
    public const TYPES = ['game.created.v1', 'game.updated.v1', 'game.activated.v1', 'game.archived.v1',
        'game_session.created.v1', 'game_session.registration_opened.v1', 'game_session.registration_closed.v1',
        'game_session.team_registered.v1', 'game_session.team_removed.v1', 'game_session.player_added_to_roster.v1',
        'game_session.player_removed_from_roster.v1', 'game_session.player_ready.v1', 'game_session.player_unready.v1',
        'game_session.billing_validated.v1', 'game_session.start_failed.v1', 'game_session.started.v1', 'game_session.paused.v1',
        'game_session.resumed.v1', 'game_session.finished.v1', 'game_session.cancelled.v1',
        'session_team.ready.v1', 'session_team.activated.v1', 'session_team.finished.v1', 'session_team.removed.v1',
        'player_session.disconnected.v1', 'player_session.reconnected.v1', 'player_session.joined.v1', 'player_session.ready.v1', 'player_session.activated.v1', 'player_session.removed.v1',
        'player_session.finished.v1', 'player_session.late_joined.v1', 'device.session_bound.v1', 'device.session_rebound.v1'];

    public function __construct(private OutboxService $outbox, private AuditService $audit) {}

    public function record(string $type, User $actor, Game|GameSession $resource, array $data = [], ?string $reason = null): void
    {
        if (! in_array($type, self::TYPES, true) || DB::transactionLevel() === 0) {
            throw new \LogicException('Canonical events require a transaction.');
        }
        $session = $resource instanceof GameSession;
        $eventId = (string) Str::uuid();
        $data += [$session ? 'session_id' : 'game_id' => $resource->public_id];
        $payload = ['event_id' => $eventId, 'event_type' => $type, 'payload_version' => 1, 'occurred_at' => now()->toISOString(),
            'producer' => 'games', 'organization_id' => $resource->organization->public_id, 'session_id' => $session ? $resource->public_id : null,
            'team_id' => $data['team_id'] ?? null, 'player_id' => $data['player_id'] ?? null,
            'actor' => ['type' => 'user', 'id' => $actor->public_id], 'request_id' => request()->attributes->get('request_id'), 'data' => $data];
        $kind = $session ? 'game_session' : 'game';
        $this->outbox->record($type, 'games', $kind, $resource->public_id, $payload, 1, $eventId);
        $this->audit->record($type, $kind, $resource->public_id, $reason, $data + ['event_id' => $eventId], $actor->id, $resource->organization_id);
    }
}

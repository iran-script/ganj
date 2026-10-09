<?php

namespace App\Services\Teams;

use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Outbox\OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeamEvents
{
    public const TYPES = ['team.created.v1', 'team.updated.v1', 'team.archived.v1', 'team.member_joined.v1', 'team.member_left.v1', 'team.member_removed.v1', 'team.captain_changed.v1', 'team.invite_created.v1', 'team.invite_revoked.v1'];

    public function __construct(private OutboxService $outbox, private AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function record(string $type, User $actor, Team $team, array $data = [], ?string $reason = null, ?User $target = null): void
    {
        if (! in_array($type, self::TYPES, true) || DB::transactionLevel() === 0) {
            throw new \LogicException('Team events require a registered type and an active transaction.');
        }
        $count = $team->activeMembers()->count();
        $data += ['team_id' => $team->public_id, 'member_count' => $count, 'eligible_for_game' => $team->status === 'ACTIVE' && $count >= 4 && $count <= 8, 'reason' => $reason];
        if ($target) {
            $data['user_id'] = $target->public_id;
        }
        $eventId = (string) Str::uuid();
        $envelope = ['event_id' => $eventId, 'event_type' => $type, 'payload_version' => 1, 'occurred_at' => now()->toISOString(), 'producer' => 'teams', 'organization_id' => $team->organization?->public_id, 'session_id' => null, 'team_id' => $team->public_id, 'player_id' => $target?->public_id, 'actor' => ['type' => 'user', 'id' => $actor->public_id], 'request_id' => request()->attributes->get('request_id'), 'data' => $data];
        $this->outbox->record($type, 'teams', 'team', $team->public_id, $envelope, 1, $eventId);
        $this->audit->record($type, 'team', $team->public_id, $reason, $data + ['event_id' => $eventId], $actor->id, $team->organization_id);
        if ($target) {
            DB::table('team_member_events')->insert(['team_id' => $team->id, 'user_id' => $target->id, 'event_type' => $type, 'actor_id' => $actor->id, 'payload' => json_encode($envelope), 'occurred_at' => now()]);
        }
    }
}

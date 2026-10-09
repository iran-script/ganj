<?php

namespace App\Services\Identity;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\Audit\AuditService;
use App\Services\Outbox\OutboxService;
use Illuminate\Support\Str;

class IdentityEvents
{
    public const TYPES = ['user.registered.v1', 'user.logged_in.v1', 'user.logged_out.v1', 'device.registered.v1', 'device.revoked.v1'];

    public function __construct(private OutboxService $outbox, private AuditService $audit) {}

    public function record(string $type, User $user, ?UserDevice $device = null, array $data = []): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown identity event.');
        }
        $id = (string) Str::uuid();
        $aggregate = str_starts_with($type, 'device.') ? 'device' : 'user';
        $resourceId = $aggregate === 'device' ? $device->public_id : $user->public_id;
        $data = array_merge($data, ['user_id' => $user->public_id, 'device_id' => $device?->public_id]);
        $envelope = ['event_id' => $id, 'event_type' => $type, 'payload_version' => 1, 'occurred_at' => now()->toISOString(), 'producer' => 'identity', 'organization_id' => null, 'session_id' => null, 'team_id' => null, 'player_id' => null, 'actor' => ['type' => 'user', 'id' => $user->public_id], 'request_id' => request()->attributes->get('request_id'), 'data' => $data];
        $this->outbox->record($type, 'identity', $aggregate, $resourceId, $envelope, 1, $id);
        $this->audit->record($type, $aggregate, $resourceId, context: $data, actorId: $user->id);
    }
}

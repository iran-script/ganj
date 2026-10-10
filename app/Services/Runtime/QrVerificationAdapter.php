<?php

namespace App\Services\Runtime;

use App\Exceptions\ApiException;
use App\Models\GameSessionPlayer;
use App\Models\TeamNodeInstance;
use App\Models\TriggerToken;
use App\Services\Qr\QrEvents;

class QrVerificationAdapter implements QRVerification
{
    public function __construct(private QrEvents $events) {}

    public function execute(TeamNodeInstance $node, ?GameSessionPlayer $player, array $evidence, string $commandId): array
    {
        if (! $player || ! isset($evidence['qr_token']) || ! is_string($evidence['qr_token'])) {
            throw new ApiException('QR_INVALID', 'A valid online QR verification token is required.', [], 409);
        }
        $hash = hash('sha256', $evidence['qr_token']);
        $token = TriggerToken::where('token_hash', $hash)->lockForUpdate()->first();
        $replay = ! $token || $token->consumed_at !== null || $token->revoked_at !== null || $token->expires_at->isPast();
        if ($replay || $token->game_session_id !== $player->game_session_id || $token->team_id !== $node->instance->registration->team_id
            || $token->player_id !== $player->id || $token->device_id !== $player->active_device_id || $token->node_instance_id !== $node->id) {
            if ($token) {
                $this->events->record('qr.token_replay_detected.v1', $player, ['node_instance_id' => $node->public_id, 'command_id' => $commandId]);
            }
            throw new ApiException('QR_REPLAYED', 'The QR verification token is no longer valid.', [], 409);
        }
        $token->forceFill(['consumed_at' => now()])->save();
        $this->events->record('qr.token_consumed.v1', $player, ['node_instance_id' => $node->public_id, 'command_id' => $commandId]);

        return ['accepted' => true, 'complete' => true, 'result' => 'ACCEPTED'];
    }
}

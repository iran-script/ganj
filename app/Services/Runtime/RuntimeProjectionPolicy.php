<?php

namespace App\Services\Runtime;

use App\Models\EvidenceSubmission;
use App\Models\GameSessionPlayer;
use App\Models\MediaAsset;
use App\Models\TeamNodeInstance;
use App\Services\Media\MediaService;

class RuntimeProjectionPolicy
{
    public function __construct(private RuntimeRoles $roles) {}

    public function config(TeamNodeInstance $node, ?GameSessionPlayer $player): array
    {
        $config = $node->node->config;
        $safe = array_intersect_key($config, array_flip(['offline_submission_allowed', 'prompt', 'text', 'options', 'multi_select', 'duration_seconds', 'minimum_player_count', 'subgroup_id']));
        $safe += array_intersect_key($config, array_flip(['mode', 'starts_at', 'ends_at', 'timeout_seconds', 'guidance_text', 'success_message', 'invalid_message', 'instruction',
            'description', 'cta_label', 'require_acknowledgement', 'max_size_mb', 'max_duration_seconds', 'allowed_mime_types', 'camera_policy', 'record_policy',
            'require_location', 'require_timestamp', 'allow_resubmit', 'approval_mode', 'reject_message']));
        if ($node->node->node_type === 'RandomPlayer' && $player && ($node->state_json['selected_player_id'] ?? null) === $player->public_id) {
            $safe['text'] = $config['private_text'] ?? '';
        }
        if ($node->node->node_type === 'MediaReveal') {
            $asset = MediaAsset::where('public_id', $config['media_asset_id'])->where('organization_id', $node->instance->session->organization_id)->first();
            if ($asset) {
                $safe['media'] = app(MediaService::class)->project($asset);
            }
        }
        if ($player && in_array($node->node->node_type, ['PhotoSubmission', 'AudioSubmission', 'FileSubmission'], true)) {
            $submission = EvidenceSubmission::where('team_node_instance_id', $node->id)->latest('id')->first();
            $safe['submission'] = $submission ? ['id' => $submission->public_id, 'status' => $submission->status,
                'review_message' => $submission->review_message, 'can_download' => $submission->game_session_player_id === $player->id,
                'asset' => app(MediaService::class)->project($submission->asset)] : null;
            $safe['offline_submission_allowed'] = false;
        }
        if ($player && isset($config['role_content'])) {
            $role = $this->roles->roleFor($node->instance, $player);
            if ($role !== null && isset($config['role_content'][$role]) && is_array($config['role_content'][$role])) {
                $safe = array_replace($safe, array_intersect_key($config['role_content'][$role], array_flip(['prompt', 'text', 'options'])));
            }
        }
        if (isset($node->state_json['subgroup_id'])) {
            $safe['subgroup_id'] = $node->state_json['subgroup_id'];
            if ($player) {
                $safe['subgroup_member'] = in_array($player->public_id, $node->state_json['subgroup_member_ids'] ?? [], true);
            }
        }
        if ($node->node->node_type === 'Give Hint') {
            return [];
        }

        return $safe;
    }
}

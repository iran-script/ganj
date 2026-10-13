<?php

namespace Database\Factories;

use App\Models\EvidenceSubmission;
use App\Models\GameSession;
use App\Models\GameSessionPlayer;
use App\Models\TeamNodeInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvidenceSubmission>
 */
class EvidenceSubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_node_instance_id' => TeamNodeInstance::factory(),
            'game_session_id' => fn (array $attributes): int => TeamNodeInstance::findOrFail($attributes['team_node_instance_id'])->instance->game_session_id,
            'organization_id' => fn (array $attributes): int => GameSession::findOrFail($attributes['game_session_id'])->organization_id,
            'game_session_player_id' => fn (array $attributes): int => GameSessionPlayer::factory()->create([
                'game_session_team_id' => TeamNodeInstance::findOrFail($attributes['team_node_instance_id'])->instance->game_session_team_id])->id,
            'media_asset_id' => fn (array $attributes): int => MediaAssetFactory::new()->create(['organization_id' => $attributes['organization_id'],
                'created_by' => GameSessionPlayer::findOrFail($attributes['game_session_player_id'])->user_id, 'purpose' => 'EVIDENCE'])->id,
            'status' => 'PENDING', 'idempotency_key' => fake()->uuid(), 'request_hash' => hash('sha256', fake()->uuid()), 'location_verified' => false,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'created_by' => User::factory(),
            'name' => fake()->words(3, true), 'kind' => 'FILE', 'purpose' => 'LIBRARY', 'disk' => 'local',
            'object_key' => 'nexo-media/'.fake()->uuid().'.bin', 'mime_type' => 'application/pdf',
            'size_bytes' => 100, 'sha256' => hash('sha256', fake()->uuid()),
        ];
    }
}

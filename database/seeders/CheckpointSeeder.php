<?php

namespace Database\Seeders;

use App\Models\Checkpoint;
use App\Models\OrganizationMember;
use App\Services\Locations\CheckpointService;
use Illuminate\Database\Seeder;

class CheckpointSeeder extends Seeder
{
    /** Explicit authoring example only; never included in the production DatabaseSeeder. */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $member = OrganizationMember::where('role', 'ORGANIZATION_ADMIN')->where('status', 'ACTIVE')->first();
        if (! $member || Checkpoint::where('organization_id', $member->organization_id)->where('name', 'Checkpoint authoring example')->exists()) {
            return;
        }
        app(CheckpointService::class)->save($member->user, [
            'organization_id' => $member->organization->public_id,
            'name' => 'Checkpoint authoring example',
            'type' => 'POINT',
            'geom' => ['type' => 'Point', 'coordinates' => [0, 0]],
            'radius_meters' => 25,
            'status' => 'INACTIVE',
        ]);
    }
}

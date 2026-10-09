<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\OrganizationSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrganizationSettingsSeeder extends Seeder
{
    public function run(): void
    {
        Organization::query()->select('id')->eachById(function (Organization $organization): void {
            DB::transaction(function () use ($organization): void {
                Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
                if (! $organization->settings()->exists()) {
                    $settings = new OrganizationSetting;
                    $settings->forceFill(['organization_id' => $organization->id])->save();
                }
            });
        });
    }
}

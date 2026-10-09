<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationSetting> */
class OrganizationSettingFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'branding_json' => [], 'commercial_settings_json' => [], 'invoice_profile_json' => []];
    }
}

<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserDevice> */
class UserDeviceFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'device_uuid' => fake()->uuid(), 'device_name' => 'Test phone', 'platform' => 'android', 'app_version' => '1.0', 'last_seen_at' => now(), 'last_ip' => '127.0.0.1'];
    }
}

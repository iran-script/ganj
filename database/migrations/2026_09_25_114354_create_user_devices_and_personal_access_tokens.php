<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('device_uuid');
            $table->string('device_name');
            $table->string('platform', 32);
            $table->string('app_version', 64);
            $table->timestampTz('last_seen_at')->nullable()->index();
            $table->ipAddress('last_ip')->nullable();
            $table->timestampTz('revoked_at')->nullable()->index();
            $table->timestampsTz();
            $table->unique(['user_id', 'device_uuid']);
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->foreignId('user_device_id')->constrained('user_devices')->restrictOnDelete();
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('user_devices');
    }
};

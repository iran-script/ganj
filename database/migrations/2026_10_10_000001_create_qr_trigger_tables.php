<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_triggers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('identifier_hash', 64)->unique();
            $table->foreignId('checkpoint_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('ACTIVE');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->index(['organization_id', 'status']);
        });
        Schema::create('trigger_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('player_id')->constrained('game_session_players')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('user_devices')->restrictOnDelete();
            $table->foreignId('node_instance_id')->nullable()->constrained('team_node_instances')->restrictOnDelete();
            $table->foreignId('qr_trigger_id')->nullable()->constrained('qr_triggers')->restrictOnDelete();
            $table->string('trigger_type', 32)->default('QR');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['game_session_id', 'team_id']);
            $table->index('expires_at');
        });
        Schema::create('qr_scan_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('player_id')->constrained('game_session_players')->restrictOnDelete();
            $table->foreignId('qr_trigger_id')->nullable()->constrained('qr_triggers')->restrictOnDelete();
            $table->foreignId('node_instance_id')->nullable()->constrained('team_node_instances')->restrictOnDelete();
            $table->unsignedBigInteger('location_event_id')->nullable();
            $table->string('result', 24);
            $table->string('reason_code', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['game_session_id', 'occurred_at']);
            $table->index(['player_id', 'occurred_at']);
            $table->index(['result', 'reason_code']);
        });
        DB::statement("ALTER TABLE qr_triggers ADD CONSTRAINT qr_trigger_status_check CHECK (status IN ('ACTIVE','INACTIVE','REVOKED'))");
        DB::statement("ALTER TABLE qr_scan_events ADD CONSTRAINT qr_scan_result_check CHECK (result IN ('ACCEPTED','REJECTED','RESOLVED','TOKEN_ISSUED','REPLAY_DETECTED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_scan_events');
        Schema::dropIfExists('trigger_tokens');
        Schema::dropIfExists('qr_triggers');
    }
};

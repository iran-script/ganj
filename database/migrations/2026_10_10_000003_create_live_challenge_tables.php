<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenge_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->string('type', 32);
            $table->jsonb('config')->default('{}');
            $table->string('status', 16)->default('ACTIVE');
            $table->unsignedInteger('version_no')->default(1);
            $table->timestampsTz();
            $table->unique(['organization_id', 'name', 'version_no']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('live_challenges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('challenge_templates')->restrictOnDelete();
            $table->string('type', 32);
            $table->unsignedInteger('priority')->default(0);
            $table->string('status', 16)->default('DRAFT');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->jsonb('config')->default('{}');
            $table->jsonb('reward_config')->default('{}');
            $table->jsonb('penalty_config')->default('{}');
            $table->string('interrupt_policy', 24)->default('PARALLEL');
            $table->jsonb('completion_rule')->default('{}');
            $table->string('fairness_class', 32)->default('SIDE_OBJECTIVE');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['game_session_id', 'status', 'priority']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('live_challenge_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_challenge_id')->constrained()->restrictOnDelete();
            $table->string('target_type', 16);
            $table->string('target_id', 255);
            $table->string('status', 16)->default('SENT');
            $table->timestampTz('seen_at')->nullable();
            $table->timestampTz('acked_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['live_challenge_id', 'target_type', 'target_id'], 'live_challenge_target_unique');
            $table->index(['target_type', 'target_id', 'status']);
        });

        Schema::create('live_challenge_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('submission_id')->unique();
            $table->foreignId('live_challenge_id')->constrained()->restrictOnDelete();
            $table->foreignId('target_id')->constrained('live_challenge_targets')->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->jsonb('evidence')->default('{}');
            $table->string('result', 16);
            $table->string('idempotency_key')->unique();
            $table->timestampTz('occurred_at');
            $table->index(['live_challenge_id', 'result']);
        });

        Schema::create('session_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('message_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->string('target_type', 16);
            $table->string('target_id', 255);
            $table->string('priority', 16)->default('NORMAL');
            $table->text('body');
            $table->text('reason')->nullable();
            $table->timestampTz('created_at');
            $table->index(['game_session_id', 'created_at']);
        });

        DB::unprepared("ALTER TABLE challenge_templates ADD CONSTRAINT challenge_template_status_check CHECK (status IN ('ACTIVE','INACTIVE','ARCHIVED'))");
        DB::unprepared("ALTER TABLE live_challenges ADD CONSTRAINT live_challenge_status_check CHECK (status IN ('DRAFT','SCHEDULED','ACTIVE','COMPLETED','EXPIRED','CANCELLED'))");
        DB::unprepared("ALTER TABLE live_challenges ADD CONSTRAINT live_challenge_interrupt_check CHECK (interrupt_policy IN ('PARALLEL','SOFT_INTERRUPT','HARD_INTERRUPT'))");
        DB::unprepared("ALTER TABLE live_challenges ADD CONSTRAINT live_challenge_fairness_check CHECK (fairness_class IN ('COSMETIC','SIDE_OBJECTIVE','COMPETITIVE_LOW_IMPACT','COMPETITIVE'))");
        DB::unprepared("ALTER TABLE live_challenge_targets ADD CONSTRAINT live_challenge_target_type_check CHECK (target_type IN ('PLAYER','TEAM','SESSION'))");
        DB::unprepared("ALTER TABLE live_challenge_targets ADD CONSTRAINT live_challenge_target_status_check CHECK (status IN ('SENT','SEEN','ACKED','STARTED','COMPLETED','FAILED','EXPIRED','CANCELLED'))");
        DB::unprepared("ALTER TABLE live_challenge_submissions ADD CONSTRAINT live_challenge_submission_result_check CHECK (result IN ('ACCEPTED','REJECTED'))");
        DB::unprepared("ALTER TABLE session_messages ADD CONSTRAINT session_message_target_type_check CHECK (target_type IN ('PLAYER','TEAM','SESSION'))");
        DB::unprepared("ALTER TABLE session_messages ADD CONSTRAINT session_message_priority_check CHECK (priority IN ('NORMAL','EMERGENCY'))");
    }

    public function down(): void
    {
        foreach (['session_messages', 'live_challenge_submissions', 'live_challenge_targets', 'live_challenges', 'challenge_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

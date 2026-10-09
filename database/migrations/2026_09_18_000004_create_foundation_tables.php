<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->string('key', 128)->primary();
            $table->string('fingerprint', 64);
            $table->string('status', 32);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index('expires_at');
        });
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 128);
            $table->string('resource_type', 128);
            $table->string('resource_id', 128);
            $table->text('reason')->nullable();
            $table->jsonb('context')->nullable();
            $table->string('request_id', 128)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');
            $table->index(['resource_type', 'resource_id']);
            $table->index('request_id');
        });
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('topic', 128);
            $table->string('producer', 128);
            $table->string('aggregate_type', 128);
            $table->string('aggregate_id', 128);
            $table->unsignedInteger('payload_version');
            $table->jsonb('payload');
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'available_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('idempotency_keys');
    }
};

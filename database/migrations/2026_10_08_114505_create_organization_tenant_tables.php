<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->string('status', 16)->default('ACTIVE')->index();
            $table->string('timezone_default', 64)->default('UTC');
            $table->timestampsTz();
            $table->timestampTz('archived_at')->nullable();
        });
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_status_check CHECK ((status = 'ACTIVE' AND archived_at IS NULL) OR (status = 'ARCHIVED' AND archived_at IS NOT NULL))");
        Schema::create('organization_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 32)->index();
            $table->string('status', 16)->default('ACTIVE')->index();
            $table->timestampTz('joined_at');
            $table->timestampTz('left_at')->nullable();
            $table->index(['organization_id', 'user_id']);
            $table->index(['user_id', 'status', 'organization_id']);
        });
        DB::statement("CREATE UNIQUE INDEX organization_members_active_unique ON organization_members (organization_id, user_id) WHERE status = 'ACTIVE'");
        DB::statement("ALTER TABLE organization_members ADD CONSTRAINT organization_members_role_check CHECK (role IN ('ORGANIZATION_ADMIN', 'GAMEMASTER', 'STAFF'))");
        DB::statement("ALTER TABLE organization_members ADD CONSTRAINT organization_members_status_check CHECK ((status IN ('ACTIVE', 'SUSPENDED') AND left_at IS NULL) OR (status IN ('LEFT', 'REMOVED') AND left_at IS NOT NULL))");
        DB::statement('ALTER TABLE organization_members ADD CONSTRAINT organization_members_period_check CHECK (left_at IS NULL OR left_at >= joined_at)');
        Schema::create('organization_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->jsonb('branding_json')->default('{}');
            $table->jsonb('commercial_settings_json')->default('{}');
            $table->jsonb('invoice_profile_json')->default('{}');
            $table->string('payment_config_ref')->nullable();
            $table->timestampsTz();
        });
        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('resource_type', 64)->nullable();
            $table->string('resource_id', 128)->nullable();
            $table->string('role_code', 32);
            $table->timestampTz('created_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->index(['resource_type', 'resource_id']);
        });
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_baseline_scope_check CHECK (role_code = 'SUPER_ADMIN' AND organization_id IS NULL AND resource_type IS NULL AND resource_id IS NULL)");
        DB::statement('CREATE UNIQUE INDEX role_assignments_active_global_unique ON role_assignments (user_id, role_code) WHERE revoked_at IS NULL');
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['organization_id', 'occurred_at']);
        });
        DB::unprepared("CREATE OR REPLACE FUNCTION reject_audit_event_mutation() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Audit events are append-only'' USING ERRCODE = ''23514''; END;'");
        DB::unprepared('CREATE TRIGGER audit_events_append_only BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_append_only ON audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS reject_audit_event_mutation()');
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'occurred_at']);
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('organization_settings');
        Schema::dropIfExists('organization_members');
        Schema::dropIfExists('organizations');
    }
};

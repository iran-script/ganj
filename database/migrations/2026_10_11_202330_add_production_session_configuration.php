<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->jsonb('pricing')->nullable();
            $table->foreignId('scenario_version_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('session_configuration_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('game_session_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('scenario_version_id')->constrained()->restrictOnDelete();
            $table->jsonb('pricing');
            $table->timestampTz('created_at');
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_session_configuration() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE configured_price jsonb; configured_version bigint; owner_id bigint; session_owner bigint; session_state text;
BEGIN
    SELECT g.pricing, g.scenario_version_id, g.organization_id, s.organization_id, s.status
      INTO configured_price, configured_version, owner_id, session_owner, session_state
      FROM game_sessions s JOIN games g ON g.id = s.game_id WHERE s.id = NEW.game_session_id;
    IF session_state IS DISTINCT FROM 'DRAFT' OR owner_id IS DISTINCT FROM session_owner
       OR configured_version IS DISTINCT FROM NEW.scenario_version_id OR configured_price IS DISTINCT FROM NEW.pricing
       OR NOT EXISTS (SELECT 1 FROM scenario_versions v JOIN scenarios sc ON sc.id = v.scenario_id
                      WHERE v.id = NEW.scenario_version_id AND v.status = 'PUBLISHED' AND sc.organization_id = owner_id) THEN
        RAISE EXCEPTION 'Session configuration must pin the published tenant game configuration before registration' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER session_configuration_guard BEFORE INSERT ON session_configuration_snapshots FOR EACH ROW EXECUTE FUNCTION guard_session_configuration();
SQL);
        DB::unprepared('CREATE TRIGGER session_configuration_append_only BEFORE UPDATE OR DELETE ON session_configuration_snapshots FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation()');
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_baseline_scope_check');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_baseline_scope_check CHECK ((role_code IN ('SUPER_ADMIN','ORGANIZATION_OPERATOR') AND organization_id IS NULL AND resource_type IS NULL AND resource_id IS NULL) OR (role_code = 'GAMEMASTER' AND organization_id IS NOT NULL AND resource_type IS NOT NULL AND resource_type IN ('game','game_session') AND resource_id IS NOT NULL))");
    }

    public function down(): void
    {
        if (DB::table('role_assignments')->where('role_code', 'ORGANIZATION_OPERATOR')->exists() || DB::table('session_configuration_snapshots')->exists()) {
            throw new LogicException('Admission or operator audit history exists; use a forward migration instead.');
        }
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_baseline_scope_check');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_baseline_scope_check CHECK ((role_code = 'SUPER_ADMIN' AND organization_id IS NULL AND resource_type IS NULL AND resource_id IS NULL) OR (role_code = 'GAMEMASTER' AND organization_id IS NOT NULL AND resource_type IS NOT NULL AND resource_type IN ('game','game_session') AND resource_id IS NOT NULL))");
        Schema::dropIfExists('session_configuration_snapshots');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_session_configuration()');
        Schema::table('games', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('scenario_version_id');
            $table->dropColumn('pricing');
        });
    }
};

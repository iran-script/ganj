<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_scenario_instances', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('game_session_team_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('scenario_version_id')->constrained()->restrictOnDelete();
            $t->string('status')->default('NOT_STARTED');
            $t->unsignedBigInteger('current_node_instance_id')->nullable();
            $t->string('random_seed', 64);
            $t->bigInteger('score_cached')->default(0);
            $t->bigInteger('effective_elapsed_seconds')->default(0);
            $t->jsonb('pause_sources')->default('[]');
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->index(['game_session_id', 'status']);
        });
        Schema::create('team_node_instances', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('team_scenario_instance_id')->constrained()->restrictOnDelete();
            $t->foreignId('scenario_node_id')->constrained()->restrictOnDelete();
            $t->string('status')->default('LOCKED');
            $t->integer('attempt_count')->default(0);
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->jsonb('state_json')->default('{}');
            $t->integer('version_no')->default(1);
            $t->timestampsTz();
            $t->unique(['team_scenario_instance_id', 'scenario_node_id'], 'runtime_node_unique');
            $t->unique(['team_scenario_instance_id', 'id'], 'runtime_node_context_unique');
            $t->index(['team_scenario_instance_id', 'status']);
        });
        Schema::table('team_scenario_instances', function (Blueprint $t): void {
            $t->foreign(['id', 'current_node_instance_id'], 'runtime_focus_fk')->references(['team_scenario_instance_id', 'id'])->on('team_node_instances')->restrictOnDelete();
        });
        Schema::create('runtime_commands', function (Blueprint $t): void {
            $t->id();
            $t->uuid('command_id')->unique();
            $t->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('team_scenario_instance_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('player_id')->nullable()->constrained('game_session_players')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('command_type');
            $t->string('idempotency_key')->unique();
            $t->string('request_hash', 64);
            $t->jsonb('request_payload');
            $t->jsonb('result_payload')->nullable();
            $t->string('status');
            $t->timestampTz('created_at');
            $t->timestampTz('completed_at')->nullable();
            $t->index(['team_scenario_instance_id', 'id']);
        });
        Schema::create('runtime_events', function (Blueprint $t): void {
            $t->id();
            $t->uuid('event_id')->unique();
            $t->string('aggregate_type');
            $t->uuid('aggregate_id');
            $t->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('team_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('player_id')->nullable()->constrained('game_session_players')->restrictOnDelete();
            $t->string('event_type');
            $t->integer('payload_version')->default(1);
            $t->jsonb('payload');
            $t->string('actor_type');
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('occurred_at');
            $t->timestampTz('created_at');
            $t->index(['game_session_id', 'occurred_at']);
            $t->index(['team_id', 'occurred_at']);
            $t->index(['aggregate_type', 'aggregate_id', 'id'], 'runtime_timeline_index');
        });
        Schema::create('runtime_variables', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('team_scenario_instance_id')->constrained()->restrictOnDelete();
            $t->foreignId('player_id')->nullable()->constrained('game_session_players')->restrictOnDelete();
            $t->unsignedBigInteger('node_instance_id')->nullable();
            $t->string('key');
            $t->jsonb('value');
            $t->string('value_type');
            $t->timestampTz('updated_at');
            $t->foreign(['team_scenario_instance_id', 'node_instance_id'], 'runtime_variable_node_fk')->references(['team_scenario_instance_id', 'id'])->on('team_node_instances')->restrictOnDelete();
        });
        Schema::create('runtime_timers', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('team_scenario_instance_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('node_instance_id')->nullable();
            $t->string('timer_type');
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('paused_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->bigInteger('accumulated_paused_seconds')->default(0);
            $t->integer('duration_seconds')->nullable();
            $t->string('status')->default('NOT_STARTED');
            $t->foreign(['team_scenario_instance_id', 'node_instance_id'], 'runtime_timer_node_fk')->references(['team_scenario_instance_id', 'id'])->on('team_node_instances')->restrictOnDelete();
            $t->index(['status', 'started_at']);
        });
        Schema::create('player_role_assignments', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('team_scenario_instance_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('node_instance_id');
            $t->foreignId('game_session_player_id')->constrained()->restrictOnDelete();
            $t->string('role_code');
            $t->integer('slot_no');
            $t->timestampTz('assigned_at');
            $t->timestampTz('released_at')->nullable();
            $t->string('assignment_source');
            $t->foreign(['team_scenario_instance_id', 'node_instance_id'], 'runtime_role_node_fk')->references(['team_scenario_instance_id', 'id'])->on('team_node_instances')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE team_scenario_instances ADD CHECK (status IN ('NOT_STARTED','ACTIVE','PAUSED','COMPLETED','FAILED','CANCELLED')), ADD CHECK (effective_elapsed_seconds >= 0);
ALTER TABLE team_node_instances ADD CHECK (status IN ('LOCKED','AVAILABLE','ACTIVE','COMPLETED','FAILED','SKIPPED')), ADD CHECK (attempt_count >= 0 AND version_no > 0);
ALTER TABLE runtime_commands ADD CHECK (status IN ('RECEIVED','PROCESSING','SUCCEEDED','REJECTED','FAILED'));
ALTER TABLE runtime_timers ADD CHECK (status IN ('NOT_STARTED','RUNNING','PAUSED','FINISHED','CANCELLED')), ADD CHECK (accumulated_paused_seconds >= 0 AND (duration_seconds IS NULL OR duration_seconds > 0));
ALTER TABLE player_role_assignments ADD CHECK (slot_no BETWEEN 1 AND 4), ADD CHECK (role_code IN ('SCANNER','NAVIGATOR','DECODER','AR_OPERATOR'));
CREATE UNIQUE INDEX runtime_variable_scope_unique ON runtime_variables (team_scenario_instance_id, player_id, node_instance_id, key) NULLS NOT DISTINCT;
CREATE UNIQUE INDEX runtime_timer_scope_unique ON runtime_timers (team_scenario_instance_id, node_instance_id, timer_type) NULLS NOT DISTINCT;
CREATE UNIQUE INDEX runtime_active_slot_unique ON player_role_assignments (team_scenario_instance_id,slot_no) WHERE released_at IS NULL;
CREATE UNIQUE INDEX runtime_active_player_unique ON player_role_assignments (team_scenario_instance_id,game_session_player_id) WHERE released_at IS NULL;
CREATE UNIQUE INDEX runtime_active_role_unique ON player_role_assignments (team_scenario_instance_id,role_code) WHERE released_at IS NULL;
CREATE UNIQUE INDEX runtime_barrier_contribution_unique ON runtime_events (aggregate_id,player_id) WHERE event_type='runtime.node_submission_received.v1' AND payload->'data'->>'contribution'='true';
CREATE TRIGGER runtime_events_append_only BEFORE UPDATE OR DELETE ON runtime_events FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation();
CREATE OR REPLACE FUNCTION guard_runtime_context() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE instance_row team_scenario_instances; definition scenario_variables;
BEGIN
    IF TG_TABLE_NAME = 'team_scenario_instances' THEN
        IF NOT EXISTS (SELECT 1 FROM game_session_teams t JOIN game_sessions s ON s.id=t.game_session_id
          JOIN scenario_versions v ON v.id=NEW.scenario_version_id JOIN scenarios c ON c.id=v.scenario_id
          WHERE t.id=NEW.game_session_team_id AND s.id=NEW.game_session_id AND c.organization_id=s.organization_id
          AND v.status IN ('PUBLISHED','ARCHIVED')) THEN RAISE EXCEPTION 'Invalid runtime pin' USING ERRCODE='23514'; END IF;
        IF TG_OP='UPDATE' AND (NEW.game_session_id,NEW.game_session_team_id,NEW.scenario_version_id,NEW.random_seed) IS DISTINCT FROM (OLD.game_session_id,OLD.game_session_team_id,OLD.scenario_version_id,OLD.random_seed)
        THEN RAISE EXCEPTION 'Runtime pin is immutable' USING ERRCODE='23514'; END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO instance_row FROM team_scenario_instances WHERE id=NEW.team_scenario_instance_id;
    IF TG_TABLE_NAME='team_node_instances' THEN
        IF NOT EXISTS (SELECT 1 FROM scenario_nodes WHERE id=NEW.scenario_node_id AND scenario_version_id=instance_row.scenario_version_id)
        THEN RAISE EXCEPTION 'Node version mismatch' USING ERRCODE='23514'; END IF;
    ELSIF TG_TABLE_NAME='player_role_assignments' THEN
        IF NOT EXISTS (SELECT 1 FROM game_session_players WHERE id=NEW.game_session_player_id AND game_session_team_id=instance_row.game_session_team_id)
        THEN RAISE EXCEPTION 'Role player mismatch' USING ERRCODE='23514'; END IF;
        IF TG_OP='UPDATE' AND (OLD.released_at IS NOT NULL OR (to_jsonb(NEW)-'released_at') IS DISTINCT FROM (to_jsonb(OLD)-'released_at'))
        THEN RAISE EXCEPTION 'Role history immutable' USING ERRCODE='23514'; END IF;
    ELSIF TG_TABLE_NAME='runtime_variables' THEN
        SELECT * INTO definition FROM scenario_variables WHERE scenario_version_id=instance_row.scenario_version_id AND key=NEW.key;
        IF definition.id IS NULL OR definition.type<>NEW.value_type
          OR (definition.scope='PLAYER')<>(NEW.player_id IS NOT NULL)
          OR (definition.scope='NODE')<>(NEW.node_instance_id IS NOT NULL)
        THEN RAISE EXCEPTION 'Variable scope mismatch' USING ERRCODE='23514'; END IF;
        IF NEW.player_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM game_session_players WHERE id=NEW.player_id AND game_session_team_id=instance_row.game_session_team_id)
        THEN RAISE EXCEPTION 'Variable player mismatch' USING ERRCODE='23514'; END IF;
        IF (NEW.value_type='STRING' AND jsonb_typeof(NEW.value)<>'string')
          OR (NEW.value_type='BOOLEAN' AND jsonb_typeof(NEW.value)<>'boolean')
          OR (NEW.value_type IN ('INTEGER','DECIMAL') AND jsonb_typeof(NEW.value)<>'number')
          OR (NEW.value_type='INTEGER' AND (NEW.value::text)::numeric<>trunc((NEW.value::text)::numeric))
          OR (NEW.value_type='DATETIME' AND jsonb_typeof(NEW.value)<>'string')
          OR (NEW.value_type='JSON' AND jsonb_typeof(NEW.value) NOT IN ('array','object'))
        THEN RAISE EXCEPTION 'Variable type mismatch' USING ERRCODE='23514'; END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER runtime_instance_context BEFORE INSERT OR UPDATE ON team_scenario_instances FOR EACH ROW EXECUTE FUNCTION guard_runtime_context();
CREATE TRIGGER runtime_node_context BEFORE INSERT OR UPDATE ON team_node_instances FOR EACH ROW EXECUTE FUNCTION guard_runtime_context();
CREATE TRIGGER runtime_role_context BEFORE INSERT OR UPDATE ON player_role_assignments FOR EACH ROW EXECUTE FUNCTION guard_runtime_context();
CREATE TRIGGER runtime_variable_context BEFORE INSERT OR UPDATE ON runtime_variables FOR EACH ROW EXECUTE FUNCTION guard_runtime_context();
CREATE OR REPLACE FUNCTION guard_runtime_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME='team_node_instances' THEN
        IF (NEW.team_scenario_instance_id,NEW.scenario_node_id,NEW.public_id) IS DISTINCT FROM (OLD.team_scenario_instance_id,OLD.scenario_node_id,OLD.public_id)
        THEN RAISE EXCEPTION 'Node identity immutable' USING ERRCODE='23514'; END IF;
        IF NEW.status<>OLD.status AND NOT (
          (OLD.status='LOCKED' AND NEW.status='AVAILABLE') OR
          (OLD.status='AVAILABLE' AND NEW.status IN ('ACTIVE','SKIPPED')) OR
          (OLD.status='ACTIVE' AND NEW.status IN ('COMPLETED','FAILED','SKIPPED')))
        THEN RAISE EXCEPTION 'Invalid node transition' USING ERRCODE='23514'; END IF;
        IF NEW.status='FAILED' AND OLD.status<>'FAILED' AND NOT EXISTS (
          SELECT 1 FROM scenario_nodes WHERE id=NEW.scenario_node_id AND node_type='Timer' AND config->>'on_expire'='FAIL')
        THEN RAISE EXCEPTION 'Node failure unsupported' USING ERRCODE='23514'; END IF;
    ELSE
        IF NEW.status<>OLD.status AND NOT (
          (OLD.status='NOT_STARTED' AND NEW.status IN ('ACTIVE','CANCELLED')) OR
          (OLD.status='ACTIVE' AND NEW.status IN ('PAUSED','COMPLETED','FAILED','CANCELLED')) OR
          (OLD.status='PAUSED' AND NEW.status IN ('ACTIVE','CANCELLED')))
        THEN RAISE EXCEPTION 'Invalid instance transition' USING ERRCODE='23514'; END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER runtime_node_transition BEFORE UPDATE ON team_node_instances FOR EACH ROW EXECUTE FUNCTION guard_runtime_transition();
CREATE TRIGGER runtime_instance_transition BEFORE UPDATE ON team_scenario_instances FOR EACH ROW EXECUTE FUNCTION guard_runtime_transition();

SQL);
    }

    public function down(): void
    {
        Schema::table('team_scenario_instances', fn (Blueprint $t) => $t->dropForeign('runtime_focus_fk'));
        foreach (['player_role_assignments', 'runtime_timers', 'runtime_variables', 'runtime_events', 'runtime_commands', 'team_node_instances', 'team_scenario_instances'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS guard_runtime_context()');
        DB::statement('DROP FUNCTION IF EXISTS guard_runtime_transition()');
    }
};

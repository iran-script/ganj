<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenarios', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('theme')->nullable();
            $t->string('status')->default('DRAFT');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->timestampTz('archived_at')->nullable();
            $t->index(['organization_id', 'status']);
        });
        Schema::create('scenario_versions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('scenario_id')->constrained()->restrictOnDelete();
            $t->integer('version_no');
            $t->string('status')->default('DRAFT');
            $t->uuid('revision_token');
            $t->string('difficulty', 100)->nullable();
            $t->integer('estimated_duration_seconds')->nullable();
            $t->decimal('estimated_distance_meters', 12, 2)->nullable();
            $t->string('physical_effort', 100)->nullable();
            $t->jsonb('challenge_mix')->default('{}');
            $t->timestampTz('published_at')->nullable();
            $t->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['scenario_id', 'version_no']);
            $t->index(['scenario_id', 'status']);
        });
        Schema::create('scenario_nodes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('scenario_version_id')->constrained()->restrictOnDelete();
            $t->string('node_key', 100);
            $t->string('node_type', 100);
            $t->string('title')->nullable();
            $t->jsonb('config')->default('{}');
            $t->double('position_x')->nullable();
            $t->double('position_y')->nullable();
            $t->timestampsTz();
            $t->unique(['scenario_version_id', 'node_key']);
            $t->unique(['scenario_version_id', 'id']);
        });
        Schema::create('scenario_conditions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('scenario_version_id')->constrained()->restrictOnDelete();
            $t->uuid('condition_group_id');
            $t->string('left_operand');
            $t->string('operator', 20);
            $t->jsonb('right_operand_json')->nullable();
            $t->string('value_type', 20);
            $t->integer('position');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['scenario_version_id', 'condition_group_id', 'position'], 'scenario_condition_position_unique');
            $t->index(['condition_group_id', 'position']);
        });
        Schema::create('scenario_edges', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('scenario_version_id')->constrained()->restrictOnDelete();
            $t->string('edge_key', 100);
            $t->unsignedBigInteger('from_node_id');
            $t->unsignedBigInteger('to_node_id');
            $t->integer('priority')->default(0);
            $t->uuid('condition_group_id')->nullable();
            $t->jsonb('metadata')->default('{}');
            $t->unique(['scenario_version_id', 'edge_key']);
            $t->index('from_node_id');
            $t->index('to_node_id');
            $t->foreign(['scenario_version_id', 'from_node_id'])->references(['scenario_version_id', 'id'])->on('scenario_nodes')->restrictOnDelete();
            $t->foreign(['scenario_version_id', 'to_node_id'])->references(['scenario_version_id', 'id'])->on('scenario_nodes')->restrictOnDelete();
        });
        Schema::create('scenario_variables', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('scenario_version_id')->constrained()->restrictOnDelete();
            $t->string('key', 100);
            $t->string('type', 20);
            $t->string('scope', 20);
            $t->jsonb('default_value')->nullable();
            $t->boolean('writable');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['scenario_version_id', 'key']);
        });
        Schema::create('scenario_subflows', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('status')->default('DRAFT');
            $t->timestampsTz();
            $t->index(['organization_id', 'status']);
        });
        Schema::create('scenario_subflow_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('scenario_subflow_id')->constrained()->restrictOnDelete();
            $t->integer('version_no');
            $t->string('status')->default('DRAFT');
            $t->jsonb('graph_json');
            $t->timestampTz('published_at')->nullable();
            $t->unique(['scenario_subflow_id', 'version_no']);
        });
        Schema::create('scenario_subflow_bindings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('scenario_version_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('node_id');
            $t->foreignId('subflow_version_id')->constrained('scenario_subflow_versions')->restrictOnDelete();
            $t->unique(['scenario_version_id', 'node_id']);
            $t->foreign(['scenario_version_id', 'node_id'])->references(['scenario_version_id', 'id'])->on('scenario_nodes')->restrictOnDelete();
        });
        Schema::create('scenario_validation_runs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('scenario_version_id')->constrained()->restrictOnDelete();
            $t->uuid('revision_token');
            $t->string('status');
            $t->jsonb('errors');
            $t->jsonb('warnings');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['scenario_version_id', 'id']);
            $t->index(['scenario_version_id', 'revision_token']);
        });
        Schema::create('scenario_simulation_runs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('scenario_version_id')->constrained()->restrictOnDelete();
            $t->jsonb('input_config');
            $t->jsonb('result');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
        });
        foreach (['scenarios', 'scenario_versions', 'scenario_subflows', 'scenario_subflow_versions'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_status_check CHECK (status IN ('DRAFT','PUBLISHED','ARCHIVED'))");
        }
        DB::statement("ALTER TABLE scenario_versions ADD CHECK (version_no > 0 AND (estimated_duration_seconds IS NULL OR estimated_duration_seconds > 0) AND (estimated_distance_meters IS NULL OR estimated_distance_meters >= 0)), ADD CHECK ((status = 'DRAFT' AND published_at IS NULL AND published_by IS NULL) OR (status IN ('PUBLISHED','ARCHIVED') AND published_at IS NOT NULL AND published_by IS NOT NULL))");
        DB::statement("ALTER TABLE scenario_subflow_versions ADD CHECK (version_no > 0), ADD CHECK (status = 'DRAFT' OR published_at IS NOT NULL)");
        DB::statement("ALTER TABLE scenario_validation_runs ADD CHECK (status IN ('PASS','FAIL'))");
        DB::statement("ALTER TABLE scenario_variables ADD CHECK (scope IN ('SCENARIO','TEAM','PLAYER','NODE')), ADD CHECK (type IN ('STRING','INTEGER','DECIMAL','BOOLEAN','DATETIME','JSON'))");
        DB::statement("ALTER TABLE scenario_conditions ADD CHECK (position >= 0), ADD CHECK (operator IN ('==','!=','>','>=','<','<=','IN','NOT_IN','EXISTS','NOT_EXISTS')), ADD CHECK (value_type IN ('STRING','INTEGER','DECIMAL','BOOLEAN','DATETIME','JSON'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_scenario_version_content() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Archive versions to preserve history' USING ERRCODE='23514'; END IF;
    IF OLD.status <> 'DRAFT' AND (
        (to_jsonb(NEW) - 'status' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'updated_at')
        OR NOT (NEW.status = OLD.status OR (OLD.status = 'PUBLISHED' AND NEW.status = 'ARCHIVED'))
    ) THEN RAISE EXCEPTION 'Published content is immutable' USING ERRCODE='23514'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER scenario_version_immutable BEFORE UPDATE OR DELETE ON scenario_versions FOR EACH ROW EXECUTE FUNCTION guard_scenario_version_content();
CREATE TRIGGER subflow_version_immutable BEFORE UPDATE OR DELETE ON scenario_subflow_versions FOR EACH ROW EXECUTE FUNCTION guard_scenario_version_content();
CREATE OR REPLACE FUNCTION guard_scenario_graph_content() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE version_id bigint; version_status text;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        SELECT status INTO version_status FROM scenario_versions WHERE id = OLD.scenario_version_id FOR UPDATE;
        IF version_status <> 'DRAFT' THEN RAISE EXCEPTION 'Published graph is immutable' USING ERRCODE='23514'; END IF;
    END IF;
    IF TG_OP <> 'DELETE' THEN
        SELECT status INTO version_status FROM scenario_versions WHERE id = NEW.scenario_version_id FOR UPDATE;
        IF version_status <> 'DRAFT' THEN RAISE EXCEPTION 'Published graph is immutable' USING ERRCODE='23514'; END IF;
        RETURN NEW;
    END IF;
    RETURN OLD;
END $$;
CREATE OR REPLACE FUNCTION guard_scenario_subflow_binding() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM scenario_versions v JOIN scenarios s ON s.id=v.scenario_id
        JOIN scenario_subflow_versions sv ON sv.id=NEW.subflow_version_id
        JOIN scenario_subflows sf ON sf.id=sv.scenario_subflow_id
        JOIN scenario_nodes n ON n.id=NEW.node_id AND n.scenario_version_id=v.id
        WHERE v.id=NEW.scenario_version_id AND sf.organization_id=s.organization_id
        AND sv.status IN ('PUBLISHED','ARCHIVED') AND n.node_type='Subflow'
    ) THEN RAISE EXCEPTION 'Invalid pinned subflow reference' USING ERRCODE='23514'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER scenario_binding_valid BEFORE INSERT OR UPDATE ON scenario_subflow_bindings FOR EACH ROW EXECUTE FUNCTION guard_scenario_subflow_binding();
SQL);
        foreach (['scenario_nodes', 'scenario_edges', 'scenario_conditions', 'scenario_variables', 'scenario_subflow_bindings'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION guard_scenario_graph_content()");
        }
        foreach (['scenario_validation_runs', 'scenario_simulation_runs'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation()");
        }
    }

    public function down(): void
    {
        foreach (['scenario_simulation_runs', 'scenario_validation_runs', 'scenario_subflow_bindings', 'scenario_subflow_versions', 'scenario_subflows', 'scenario_variables', 'scenario_edges', 'scenario_conditions', 'scenario_nodes', 'scenario_versions', 'scenarios'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS guard_scenario_graph_content(); DROP FUNCTION IF EXISTS guard_scenario_version_content(); DROP FUNCTION IF EXISTS guard_scenario_subflow_binding();');
    }
};

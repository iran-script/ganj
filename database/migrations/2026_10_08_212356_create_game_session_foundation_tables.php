<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->string('default_timezone', 64)->default('UTC');
            $table->timestampsTz();
            $table->timestampTz('archived_at')->nullable();
            $table->unique(['organization_id', 'slug']);
            $table->unique(['id', 'organization_id']);
            $table->index(['organization_id', 'status']);
        });
        DB::statement("ALTER TABLE games ADD CONSTRAINT games_status_check CHECK ((status IN ('DRAFT','ACTIVE') AND archived_at IS NULL) OR (status = 'ARCHIVED' AND archived_at IS NOT NULL))");
        Schema::create('game_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('DRAFT');
            $table->string('timezone', 64);
            foreach (['registration_opens_at', 'registration_closes_at', 'scheduled_start_at', 'started_at', 'finished_at', 'cancelled_at'] as $field) {
                $table->timestampTz($field)->nullable();
            }
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->foreign(['game_id', 'organization_id'])->references(['id', 'organization_id'])->on('games')->restrictOnDelete();
            $table->index(['organization_id', 'status']);
            $table->index(['game_id', 'status']);
            $table->index('scheduled_start_at');
        });
        DB::statement("ALTER TABLE game_sessions ADD CONSTRAINT game_sessions_status_check CHECK (status IN ('DRAFT','REGISTRATION','READY','RUNNING','PAUSED','FINISHED','CANCELLED'))");
        DB::statement('ALTER TABLE game_sessions ADD CONSTRAINT game_sessions_window_check CHECK (registration_opens_at IS NULL OR registration_closes_at IS NULL OR registration_opens_at < registration_closes_at)');
        Schema::create('game_session_teams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('REGISTERED');
            $table->timestampTz('registered_at');
            $table->timestampTz('removed_at')->nullable();
            $table->unsignedBigInteger('scenario_assignment_id')->nullable();
            $table->timestampsTz();
            $table->unique(['game_session_id', 'team_id']);
            $table->unique(['id', 'game_session_id']);
            $table->index(['game_session_id', 'status']);
            $table->index('team_id');
        });
        DB::statement("ALTER TABLE game_session_teams ADD CONSTRAINT session_teams_status_check CHECK (status IN ('REGISTERED','READY','ACTIVE','FINISHED','REMOVED','DISQUALIFIED'))");
        DB::statement('ALTER TABLE game_session_teams ADD CONSTRAINT session_scenario_boundary_check CHECK (scenario_assignment_id IS NULL)');
        Schema::create('game_session_players', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('game_session_team_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('participation_status', 16)->default('JOINED')->index();
            $table->foreignId('active_device_id')->nullable()->constrained('user_devices')->restrictOnDelete();
            $table->timestampTz('roster_frozen_at')->nullable();
            $table->timestampTz('joined_runtime_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->unique(['game_session_id', 'user_id']);
            $table->foreign(['game_session_team_id', 'game_session_id'], 'session_player_team_session_fk')->references(['id', 'game_session_id'])->on('game_session_teams')->restrictOnDelete();
            $table->index(['game_session_id', 'game_session_team_id']);
            $table->index('user_id');
        });
        DB::statement("ALTER TABLE game_session_players ADD CONSTRAINT session_players_status_check CHECK (participation_status IN ('JOINED','READY','ACTIVE','DISCONNECTED','REMOVED','DISQUALIFIED','FINISHED'))");
        Schema::create('game_session_roster_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_session_team_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('snapshot_json');
            $table->timestampTz('frozen_at');
            $table->timestampTz('created_at');
            $table->unique(['game_session_team_id', 'version'], 'session_roster_version_unique');
        });
        DB::statement('ALTER TABLE game_session_roster_snapshots ADD CONSTRAINT roster_version_check CHECK (version > 0)');
        Schema::create('game_session_state_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $table->string('from_state', 16);
            $table->string('to_state', 16);
            $table->string('actor_type', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['game_session_id', 'occurred_at'], 'session_transition_time_index');
        });
        Schema::create('session_ready_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_session_player_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained('user_devices')->restrictOnDelete();
            $table->boolean('ready');
            $table->timestampTz('occurred_at');
            $table->index(['game_session_player_id', 'occurred_at'], 'session_ready_time_index');
        });
        foreach (['game_session_roster_snapshots', 'game_session_state_transitions', 'session_ready_events'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation()");
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_session_roster() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE session_status text; owner_id bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Session roster history cannot be deleted' USING ERRCODE = '23514';
    END IF;
    SELECT status INTO session_status FROM game_sessions WHERE id = NEW.game_session_id FOR UPDATE;
    IF TG_OP = 'INSERT' AND session_status NOT IN ('DRAFT','REGISTRATION','READY') THEN
        RAISE EXCEPTION 'The session roster is frozen' USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'UPDATE' AND (NEW.game_session_id <> OLD.game_session_id OR NEW.game_session_team_id <> OLD.game_session_team_id OR NEW.user_id <> OLD.user_id OR (OLD.roster_frozen_at IS NOT NULL AND NEW.roster_frozen_at IS DISTINCT FROM OLD.roster_frozen_at)) THEN
        RAISE EXCEPTION 'Roster identity and frozen time are immutable' USING ERRCODE = '23514';
    END IF;
    IF NEW.active_device_id IS NOT NULL THEN
        SELECT user_id INTO owner_id FROM user_devices WHERE id = NEW.active_device_id;
        IF owner_id IS DISTINCT FROM NEW.user_id THEN
            RAISE EXCEPTION 'Device owner must match player' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER session_roster_guard BEFORE INSERT OR UPDATE OR DELETE ON game_session_players FOR EACH ROW EXECUTE FUNCTION guard_session_roster();
SQL);
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_baseline_scope_check');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_baseline_scope_check CHECK ((role_code = 'SUPER_ADMIN' AND organization_id IS NULL AND resource_type IS NULL AND resource_id IS NULL) OR (role_code = 'GAMEMASTER' AND organization_id IS NOT NULL AND resource_type IS NOT NULL AND resource_type IN ('game','game_session') AND resource_id IS NOT NULL))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_game_master_resource() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE owner_id bigint;
BEGIN
    IF NEW.role_code = 'GAMEMASTER' THEN
        IF NEW.resource_type = 'game' THEN
            SELECT organization_id INTO owner_id FROM games WHERE public_id::text = NEW.resource_id;
        ELSE
            SELECT organization_id INTO owner_id FROM game_sessions WHERE public_id::text = NEW.resource_id;
        END IF;
        IF owner_id IS NULL OR owner_id IS DISTINCT FROM NEW.organization_id THEN
            RAISE EXCEPTION 'GameMaster resource must belong to the assigned organization' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER game_master_resource_guard BEFORE INSERT OR UPDATE ON role_assignments FOR EACH ROW EXECUTE FUNCTION guard_game_master_resource();
SQL);
        DB::statement('DROP INDEX role_assignments_active_global_unique');
        DB::statement('CREATE UNIQUE INDEX role_assignments_active_global_unique ON role_assignments (user_id, role_code) WHERE revoked_at IS NULL AND organization_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX role_assignments_active_resource_unique ON role_assignments (user_id, role_code, organization_id, resource_type, resource_id) WHERE revoked_at IS NULL AND organization_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS game_master_resource_guard ON role_assignments');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_game_master_resource()');
        DB::table('role_assignments')->where('role_code', 'GAMEMASTER')->delete();
        DB::statement('DROP INDEX role_assignments_active_resource_unique');
        DB::statement('DROP INDEX role_assignments_active_global_unique');
        DB::statement('CREATE UNIQUE INDEX role_assignments_active_global_unique ON role_assignments (user_id, role_code) WHERE revoked_at IS NULL');
        DB::statement('ALTER TABLE role_assignments DROP CONSTRAINT role_assignments_baseline_scope_check');
        DB::statement("ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_baseline_scope_check CHECK (role_code = 'SUPER_ADMIN' AND organization_id IS NULL AND resource_type IS NULL AND resource_id IS NULL)");
        foreach (['session_ready_events', 'game_session_state_transitions', 'game_session_roster_snapshots', 'game_session_players', 'game_session_teams', 'game_sessions', 'games'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS guard_session_roster()');
    }
};

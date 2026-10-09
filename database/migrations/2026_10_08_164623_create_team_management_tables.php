<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->foreignId('captain_user_id')->index()->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('ACTIVE')->index();
            $table->unsignedBigInteger('avatar_media_id')->nullable();
            $table->timestampsTz();
            $table->timestampTz('archived_at')->nullable();
        });
        DB::statement("ALTER TABLE teams ADD CONSTRAINT teams_status_check CHECK ((status = 'ACTIVE' AND archived_at IS NULL) OR (status = 'ARCHIVED' AND archived_at IS NOT NULL))");
        DB::statement('ALTER TABLE teams ADD CONSTRAINT teams_name_check CHECK (length(btrim(name)) > 0)');
        DB::statement('ALTER TABLE teams ADD CONSTRAINT teams_media_unavailable_check CHECK (avatar_media_id IS NULL)');
        Schema::create('team_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('ACTIVE');
            $table->timestampTz('joined_at');
            $table->timestampTz('left_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->index(['team_id', 'status']);
            $table->index(['user_id', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX team_members_active_unique ON team_members (team_id, user_id) WHERE status = 'ACTIVE'");
        DB::statement("ALTER TABLE team_members ADD CONSTRAINT team_members_state_check CHECK ((status = 'ACTIVE' AND left_at IS NULL AND removed_by IS NULL) OR (status = 'LEFT' AND left_at IS NOT NULL AND removed_by IS NULL) OR (status = 'REMOVED' AND left_at IS NOT NULL AND removed_by IS NOT NULL))");
        DB::statement('ALTER TABLE team_members ADD CONSTRAINT team_members_period_check CHECK (left_at IS NULL OR left_at >= joined_at)');
        Schema::create('team_invites', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('team_id')->index()->constrained()->restrictOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->integer('max_uses')->nullable();
            $table->integer('uses_count')->default(0);
            $table->timestampTz('revoked_at')->nullable()->index();
            $table->timestampTz('created_at');
        });
        DB::statement('ALTER TABLE team_invites ADD CONSTRAINT team_invites_uses_check CHECK (uses_count >= 0 AND (max_uses IS NULL OR (max_uses > 0 AND uses_count <= max_uses)))');
        Schema::create('team_member_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 64);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->index(['team_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_team_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' OR TG_TABLE_NAME = 'team_member_events' THEN
        RAISE EXCEPTION 'Team history cannot be deleted or rewritten' USING ERRCODE = '23514';
    END IF;
    IF TG_TABLE_NAME = 'team_members' THEN
        IF OLD.status <> 'ACTIVE' OR NEW.team_id <> OLD.team_id OR NEW.user_id <> OLD.user_id OR NEW.joined_at <> OLD.joined_at OR NEW.created_at <> OLD.created_at THEN
            RAISE EXCEPTION 'Membership history is immutable' USING ERRCODE = '23514';
        END IF;
    ELSIF TG_TABLE_NAME = 'teams' AND OLD.status = 'ARCHIVED' AND NEW.status <> 'ARCHIVED' THEN
        RAISE EXCEPTION 'Archived teams cannot be reopened' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER teams_history BEFORE DELETE OR UPDATE ON teams FOR EACH ROW EXECUTE FUNCTION guard_team_history();
CREATE TRIGGER team_members_history BEFORE DELETE OR UPDATE ON team_members FOR EACH ROW EXECUTE FUNCTION guard_team_history();
CREATE TRIGGER team_member_events_history BEFORE DELETE OR UPDATE ON team_member_events FOR EACH ROW EXECUTE FUNCTION guard_team_history();
CREATE OR REPLACE FUNCTION check_team_captain() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'teams' THEN target_id := NEW.id; ELSE target_id := NEW.team_id; END IF;
    IF EXISTS (SELECT 1 FROM teams t WHERE t.id = target_id AND t.status = 'ACTIVE'
        AND NOT EXISTS (SELECT 1 FROM team_members m WHERE m.team_id = t.id AND m.user_id = t.captain_user_id AND m.status = 'ACTIVE')) THEN
        RAISE EXCEPTION 'An active team requires an active captain membership' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER teams_captain_check AFTER INSERT OR UPDATE ON teams DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_team_captain();
CREATE CONSTRAINT TRIGGER team_members_captain_check AFTER INSERT OR UPDATE ON team_members DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_team_captain();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('team_member_events');
        Schema::dropIfExists('team_invites');
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_team_history(); DROP FUNCTION IF EXISTS check_team_captain();');
    }
};

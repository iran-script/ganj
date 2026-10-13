<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('checkpoints', function (Blueprint $table): void {
            $table->jsonb('metadata')->default('{}');
        });
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name', 255);
            $table->string('kind', 16);
            $table->string('purpose', 16);
            $table->string('disk', 32);
            $table->string('object_key')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'purpose']);
        });
        Schema::create('evidence_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('media_asset_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_node_instance_id')->constrained()->restrictOnDelete();
            $table->foreignId('game_session_player_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('PENDING');
            $table->string('idempotency_key', 64)->unique();
            $table->char('request_hash', 64);
            $table->boolean('location_verified')->default(false);
            $table->timestampTz('captured_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_message')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['team_node_instance_id', 'game_session_player_id']);
        });
        DB::statement("ALTER TABLE media_assets ADD CHECK (kind IN ('IMAGE','AUDIO','FILE')), ADD CHECK (purpose IN ('LIBRARY','EVIDENCE')), ADD CHECK (size_bytes > 0 AND size_bytes <= 26214400)");
        DB::statement("ALTER TABLE evidence_submissions ADD CHECK (status IN ('PENDING','APPROVED','REJECTED'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_media_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Media assets are immutable; upload a new asset' USING ERRCODE='23514'; END $$;
CREATE TRIGGER media_asset_immutable BEFORE UPDATE OR DELETE ON media_assets FOR EACH ROW EXECUTE FUNCTION guard_media_immutable();
CREATE OR REPLACE FUNCTION guard_evidence_context() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM team_node_instances n JOIN team_scenario_instances i ON i.id=n.team_scenario_instance_id
 JOIN game_sessions s ON s.id=i.game_session_id JOIN game_session_players p ON p.game_session_team_id=i.game_session_team_id
 JOIN media_assets m ON m.id=NEW.media_asset_id WHERE n.id=NEW.team_node_instance_id AND p.id=NEW.game_session_player_id
 AND s.id=NEW.game_session_id AND s.organization_id=NEW.organization_id AND m.organization_id=NEW.organization_id
 AND m.created_by=p.user_id AND m.purpose='EVIDENCE')
 THEN RAISE EXCEPTION 'Invalid evidence context' USING ERRCODE='23514'; END IF;
 IF TG_OP='UPDATE' AND ((NEW.media_asset_id,NEW.organization_id,NEW.game_session_id,NEW.team_node_instance_id,NEW.game_session_player_id,NEW.public_id,NEW.request_hash,NEW.idempotency_key)
 IS DISTINCT FROM (OLD.media_asset_id,OLD.organization_id,OLD.game_session_id,OLD.team_node_instance_id,OLD.game_session_player_id,OLD.public_id,OLD.request_hash,OLD.idempotency_key)
 OR (OLD.status<>'PENDING' AND NEW.status<>OLD.status))
 THEN RAISE EXCEPTION 'Evidence identity and terminal review are immutable' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER evidence_context BEFORE INSERT OR UPDATE ON evidence_submissions FOR EACH ROW EXECUTE FUNCTION guard_evidence_context();
SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evidence_submissions');
        Schema::dropIfExists('media_assets');
        DB::statement('DROP FUNCTION IF EXISTS guard_evidence_context()');
        DB::statement('DROP FUNCTION IF EXISTS guard_media_immutable()');
        Schema::table('checkpoints', fn (Blueprint $table) => $table->dropColumn('metadata'));
    }
};

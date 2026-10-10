<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table): void {
            $table->string('leaderboard_visibility', 24)->default('FULL');
            $table->unsignedSmallInteger('leaderboard_top_n')->nullable();
        });
        DB::unprepared("ALTER TABLE game_sessions ADD CONSTRAINT leaderboard_visibility_check CHECK (leaderboard_visibility IN ('FULL','TOP_N','OWN_RANK_ONLY','HIDDEN_UNTIL_FINISH'))");

        Schema::create('score_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->constrained('game_session_teams')->restrictOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('game_session_players')->restrictOnDelete();
            $table->foreignId('node_instance_id')->nullable()->constrained('team_node_instances')->restrictOnDelete();
            $table->string('source_type', 48);
            $table->string('source_id')->nullable();
            $table->bigInteger('delta');
            $table->string('reason_code', 96);
            $table->jsonb('metadata')->default('{}');
            $table->string('idempotency_key')->unique();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');
            $table->index(['game_session_id', 'team_id', 'occurred_at']);
            $table->index(['node_instance_id', 'source_type']);
        });

        Schema::create('team_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->constrained('game_session_teams')->restrictOnDelete();
            $table->bigInteger('score')->default(0);
            $table->bigInteger('effective_elapsed_seconds')->default(0);
            $table->unsignedInteger('hints_used')->default(0);
            $table->timestampTz('finished_at')->nullable();
            $table->boolean('finalized')->default(false);
            $table->timestampTz('updated_at');
            $table->unique(['game_session_id', 'team_id']);
            $table->index(['game_session_id', 'score', 'effective_elapsed_seconds']);
        });

        Schema::create('hint_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scenario_node_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('hint_no');
            $table->text('content');
            $table->bigInteger('penalty_score')->nullable();
            $table->unsignedInteger('penalty_seconds')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['scenario_node_id', 'hint_no']);
            $table->index(['scenario_node_id', 'hint_no']);
        });

        Schema::create('hint_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('consumption_id')->unique();
            $table->foreignId('game_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->constrained('game_session_teams')->restrictOnDelete();
            $table->foreignId('player_id')->constrained('game_session_players')->restrictOnDelete();
            $table->foreignId('node_instance_id')->constrained('team_node_instances')->restrictOnDelete();
            $table->foreignId('hint_definition_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('entitlement_consumption_id')->nullable();
            $table->string('source', 24);
            $table->bigInteger('penalty_score')->default(0);
            $table->unsignedInteger('penalty_seconds')->default(0);
            $table->string('idempotency_key')->unique();
            $table->timestampTz('consumed_at');
            $table->index(['team_id', 'node_instance_id', 'consumed_at']);
        });

        DB::unprepared("ALTER TABLE score_events ADD CONSTRAINT score_source_check CHECK (source_type IN ('NODE_COMPLETION','TIME_BONUS','FIRST_ATTEMPT_BONUS','NO_HINT_BONUS','HINT_PENALTY','LIVE_CHALLENGE_REWARD','LIVE_CHALLENGE_PENALTY','MANUAL_ADJUSTMENT','ANTI_CHEAT_PENALTY'))");
        DB::unprepared("ALTER TABLE hint_consumptions ADD CONSTRAINT hint_source_check CHECK (source IN ('DEFAULT','PURCHASED','GAMEMASTER'))");
        DB::unprepared('ALTER TABLE hint_definitions ADD CONSTRAINT hint_no_check CHECK (hint_no BETWEEN 1 AND 3)');
        DB::unprepared('CREATE TRIGGER score_events_append_only BEFORE UPDATE OR DELETE ON score_events FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation()');
        DB::unprepared('CREATE TRIGGER hint_consumptions_append_only BEFORE UPDATE OR DELETE ON hint_consumptions FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation()');
    }

    public function down(): void
    {
        foreach (['hint_consumptions', 'hint_definitions', 'team_scores', 'score_events'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('ALTER TABLE game_sessions DROP CONSTRAINT IF EXISTS leaderboard_visibility_check');
        Schema::table('game_sessions', function (Blueprint $table): void {
            $table->dropColumn(['leaderboard_visibility', 'leaderboard_top_n']);
        });
    }
};

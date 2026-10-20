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
        Schema::table('team_invites', function (Blueprint $table): void {
            $table->foreignId('recipient_user_id')->nullable()->after('created_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16)->nullable()->after('uses_count');
            $table->timestampTz('responded_at')->nullable()->after('decision');
        });
        DB::statement("ALTER TABLE team_invites ADD CONSTRAINT team_invites_decision_check CHECK (decision IS NULL OR decision IN ('PENDING','ACCEPTED','DECLINED','EXPIRED'))");
        DB::statement("ALTER TABLE team_invites ADD CONSTRAINT team_invites_response_time_check CHECK ((decision IS NULL AND responded_at IS NULL) OR (decision = 'PENDING' AND responded_at IS NULL) OR (decision IN ('ACCEPTED','DECLINED','EXPIRED') AND responded_at IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX team_invites_pending_recipient_unique ON team_invites (team_id, recipient_user_id) WHERE recipient_user_id IS NOT NULL AND decision = 'PENDING' AND revoked_at IS NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS team_invites_pending_recipient_unique');
        DB::statement('ALTER TABLE team_invites DROP CONSTRAINT IF EXISTS team_invites_response_time_check');
        DB::statement('ALTER TABLE team_invites DROP CONSTRAINT IF EXISTS team_invites_decision_check');
        Schema::table('team_invites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recipient_user_id');
            $table->dropColumn(['decision', 'responded_at']);
        });
    }
};

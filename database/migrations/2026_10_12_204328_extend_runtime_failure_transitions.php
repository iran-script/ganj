<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->replaceFailureRule(true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->replaceFailureRule(false);
    }

    private function replaceFailureRule(bool $expanded): void
    {
        $legacy = "node_type='Timer' AND config->>'on_expire'='FAIL'";
        $advanced = "((node_type='Timer' AND config->>'on_expire'='FAIL') OR node_type IN ('Time','PhotoSubmission','AudioSubmission','FileSubmission') OR config ? 'timeout_seconds' OR config ? 'max_attempts' OR config->>'allow_retry'='false')";
        $definition = DB::selectOne("SELECT pg_get_functiondef('guard_runtime_transition()'::regprocedure) AS definition")->definition;
        DB::unprepared(str_replace($expanded ? $legacy : $advanced, $expanded ? $advanced : $legacy, $definition));
    }
};

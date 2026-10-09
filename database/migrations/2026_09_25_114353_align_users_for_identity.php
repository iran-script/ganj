<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('password', 'password_hash');
            $table->string('email')->nullable()->change();
            $table->uuid('public_id')->nullable();
            $table->string('mobile', 32)->nullable()->unique();
            $table->string('status', 16)->default('ACTIVE')->index();
            $table->timestampTz('last_login_at')->nullable();
        });
        DB::table('users')->orderBy('id')->eachById(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update(['public_id' => (string) Str::uuid()]);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable(false)->unique()->change();
        });
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_login_identifier_check CHECK (NULLIF(btrim(mobile), '') IS NOT NULL OR NULLIF(btrim(email), '') IS NOT NULL)");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('ACTIVE', 'SUSPENDED', 'BLOCKED'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_login_identifier_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_status_check');
        DB::statement('DROP INDEX users_email_lower_unique');
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('password_hash', 'password');
            $table->dropColumn(['public_id', 'mobile', 'status', 'last_login_at']);
        });
    }
};

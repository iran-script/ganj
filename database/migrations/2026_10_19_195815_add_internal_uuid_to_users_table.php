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
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('identity_uuid')->default(DB::raw('gen_random_uuid()'))->unique();
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_user_identity_uuid_change() RETURNS trigger AS $$
            BEGIN
                IF NEW.identity_uuid IS DISTINCT FROM OLD.identity_uuid THEN
                    RAISE EXCEPTION 'User identity UUID is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS users_identity_uuid_immutable ON users;
            CREATE TRIGGER users_identity_uuid_immutable
                BEFORE UPDATE ON users
                FOR EACH ROW EXECUTE FUNCTION prevent_user_identity_uuid_change();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('User identity UUIDs are immutable and this migration cannot be rolled back.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS users_identity_uuid_immutable ON users; DROP FUNCTION IF EXISTS prevent_user_identity_uuid_change();');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_identity_uuid_unique');
            $table->dropColumn('identity_uuid');
        });
    }
};

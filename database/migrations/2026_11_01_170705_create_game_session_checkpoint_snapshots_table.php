<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE game_session_checkpoint_snapshots (
    id bigserial PRIMARY KEY,
    game_session_id bigint NOT NULL REFERENCES game_sessions(id) ON DELETE RESTRICT,
    checkpoint_id bigint NOT NULL REFERENCES checkpoints(id) ON DELETE RESTRICT,
    geom geometry(Point,4326) NOT NULL,
    radius_meters double precision NOT NULL CHECK (radius_meters > 0 AND radius_meters <= 100000),
    config jsonb NOT NULL DEFAULT '{}',
    status varchar(10) NOT NULL CHECK (status IN ('ACTIVE','INACTIVE','ARCHIVED')),
    location_version timestamptz NOT NULL,
    snapshot_at timestamptz NOT NULL,
    UNIQUE (game_session_id, checkpoint_id),
    CHECK (ST_X(geom) BETWEEN -180 AND 180 AND ST_Y(geom) BETWEEN -90 AND 90)
);
CREATE INDEX game_session_checkpoint_snapshots_checkpoint ON game_session_checkpoint_snapshots(checkpoint_id, game_session_id);
SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_session_checkpoint_snapshots');
    }
};

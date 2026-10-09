<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE checkpoints (
 id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE,
 organization_id bigint NOT NULL REFERENCES organizations(id),
 name varchar(255) NOT NULL, type varchar(10) NOT NULL CHECK (type IN ('POINT','ZONE')),
 geom geometry(Geometry,4326) NOT NULL, radius_meters double precision,
 config jsonb NOT NULL DEFAULT '{}', status varchar(10) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE','INACTIVE','ARCHIVED')),
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 CHECK (ST_IsValid(geom) AND NOT ST_IsEmpty(geom)),
 CHECK (ST_XMin(Box3D(geom)) >= -180 AND ST_XMax(Box3D(geom)) <= 180 AND ST_YMin(Box3D(geom)) >= -90 AND ST_YMax(Box3D(geom)) <= 90),
 CHECK ((type='POINT' AND GeometryType(geom)='POINT' AND radius_meters IS NOT NULL AND radius_meters > 0 AND radius_meters <= 100000)
 OR (type='ZONE' AND GeometryType(geom) IN ('POLYGON','MULTIPOLYGON') AND radius_meters IS NULL))
);
CREATE INDEX checkpoints_geom_gist ON checkpoints USING gist(geom);
CREATE INDEX checkpoints_organization_status ON checkpoints(organization_id,status);
CREATE TABLE location_events (
 id bigserial, public_id uuid NOT NULL, game_session_id bigint NOT NULL REFERENCES game_sessions(id),
 game_session_player_id bigint NOT NULL REFERENCES game_session_players(id),
 device_id bigint NOT NULL REFERENCES user_devices(id), geom geometry(Point,4326) NOT NULL,
 accuracy double precision NOT NULL CHECK (accuracy >= 0 AND accuracy <= 100000),
 altitude double precision, speed double precision CHECK (speed >= 0 AND speed <= 10000),
 heading double precision CHECK (heading >= 0 AND heading < 360),
 client_timestamp timestamptz NOT NULL, server_received_at timestamptz NOT NULL,
 sequence bigint NOT NULL CHECK (sequence >= 0), is_late boolean NOT NULL,
 metadata jsonb NOT NULL DEFAULT '{}',
 PRIMARY KEY (id,server_received_at),
 UNIQUE(game_session_player_id,device_id,sequence,server_received_at),
 CHECK (ST_X(geom) BETWEEN -180 AND 180 AND ST_Y(geom) BETWEEN -90 AND 90)
) PARTITION BY RANGE(server_received_at);
CREATE TABLE location_events_default PARTITION OF location_events DEFAULT;
CREATE INDEX location_events_geom_gist ON location_events USING gist(geom);
CREATE INDEX location_events_player_received ON location_events(game_session_player_id,server_received_at);
CREATE INDEX location_events_session_received ON location_events(game_session_id,server_received_at);
CREATE INDEX location_events_stream_sequence ON location_events(game_session_player_id,device_id,sequence);
CREATE TABLE player_latest_locations (
 game_session_player_id bigint PRIMARY KEY REFERENCES game_session_players(id),
 device_id bigint NOT NULL REFERENCES user_devices(id), geom geometry(Point,4326) NOT NULL,
 accuracy double precision NOT NULL, client_timestamp timestamptz NOT NULL, server_received_at timestamptz NOT NULL,
 sequence bigint NOT NULL, location_event_id bigint NOT NULL, location_event_received_at timestamptz NOT NULL,
 eligible boolean NOT NULL DEFAULT false, stream_sequences jsonb NOT NULL DEFAULT '{}'
);
CREATE INDEX player_latest_locations_geom_gist ON player_latest_locations USING gist(geom);
CREATE TABLE geofence_evaluations (
 id bigserial PRIMARY KEY, game_session_id bigint NOT NULL REFERENCES game_sessions(id),
 player_id bigint NOT NULL REFERENCES game_session_players(id), checkpoint_id bigint NOT NULL REFERENCES checkpoints(id),
 node_instance_id bigint NOT NULL REFERENCES team_node_instances(id),
 location_event_id bigint NOT NULL, location_event_received_at timestamptz NOT NULL,
 result varchar(10) NOT NULL CHECK (result IN ('INSIDE','OUTSIDE','UNCERTAIN')),
 distance_meters double precision, accuracy double precision NOT NULL, evaluated_at timestamptz NOT NULL,
 dwell_started_at timestamptz, evidence_count integer NOT NULL, policy_hash varchar(64) NOT NULL,
 UNIQUE(node_instance_id,player_id,location_event_id,location_event_received_at)
);
CREATE INDEX geofence_evaluations_session_checkpoint ON geofence_evaluations(game_session_id,checkpoint_id);
CREATE INDEX geofence_evaluations_player_time ON geofence_evaluations(player_id,evaluated_at);
CREATE INDEX geofence_evaluations_current ON geofence_evaluations(node_instance_id,player_id,id DESC);
CREATE TABLE route_summaries (
 id bigserial PRIMARY KEY, game_session_player_id bigint NOT NULL REFERENCES game_session_players(id),
 simplified_geom geometry(LineString,4326) NOT NULL, distance_meters double precision NOT NULL,
 started_at timestamptz NOT NULL, ended_at timestamptz NOT NULL, generated_at timestamptz NOT NULL
);
CREATE INDEX route_summaries_player_time ON route_summaries(game_session_player_id,started_at);
CREATE OR REPLACE FUNCTION guard_location_context() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM game_session_players p JOIN user_devices d ON d.id=NEW.device_id
 WHERE p.id=NEW.game_session_player_id AND p.game_session_id=NEW.game_session_id
 AND p.user_id=d.user_id AND p.active_device_id=d.id AND d.revoked_at IS NULL)
 THEN RAISE EXCEPTION 'Invalid location Device/Session context' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION guard_location_retention() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cutoff timestamptz;
BEGIN
 cutoff := nullif(current_setting('nexo.location_retention_cutoff',true),'')::timestamptz;
 IF cutoff IS NULL THEN RAISE EXCEPTION 'Location history is append only' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='geofence_evaluations' THEN
   IF OLD.evaluated_at >= cutoff THEN RAISE EXCEPTION 'Unexpired evidence cannot be purged' USING ERRCODE='23514'; END IF;
 ELSE
   IF OLD.server_received_at >= cutoff THEN RAISE EXCEPTION 'Unexpired sample cannot be purged' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN OLD;
END $$;
CREATE TRIGGER location_events_retention BEFORE DELETE ON location_events FOR EACH ROW EXECUTE FUNCTION guard_location_retention();
CREATE TRIGGER geofence_evaluations_retention BEFORE DELETE ON geofence_evaluations FOR EACH ROW EXECUTE FUNCTION guard_location_retention();
CREATE TRIGGER location_context BEFORE INSERT ON location_events FOR EACH ROW EXECUTE FUNCTION guard_location_context();
CREATE TRIGGER location_events_no_update BEFORE UPDATE ON location_events FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation();
CREATE TRIGGER geofence_evaluations_no_update BEFORE UPDATE ON geofence_evaluations FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation();
SQL);
        $start = now()->utc()->startOfMonth();
        for ($offset = -1; $offset <= 2; $offset++) {
            $from = $start->copy()->addMonths($offset);
            $to = $from->copy()->addMonth();
            DB::statement('CREATE TABLE location_events_'.$from->format('Ym')." PARTITION OF location_events FOR VALUES FROM ('".$from->format('Y-m-d')."') TO ('".$to->format('Y-m-d')."')");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE route_summaries, geofence_evaluations, player_latest_locations, location_events, checkpoints; DROP FUNCTION guard_location_context(); DROP FUNCTION guard_location_retention();');
    }
};

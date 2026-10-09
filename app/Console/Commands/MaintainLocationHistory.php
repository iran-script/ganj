<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainLocationHistory extends Command
{
    protected $signature = 'location:maintain-history';

    protected $description = 'Prepare location partitions and purge expired raw evidence in bounded batches';

    public function handle(): int
    {
        $retention = (int) config('location.retention_days');
        if ($retention < 1) {
            $this->error('Location retention must be positive.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($retention): void {
            DB::statement('SELECT pg_advisory_xact_lock(209203508)');
            $from = now()->utc()->startOfMonth()->addMonths(2);
            $name = 'location_events_'.$from->format('Ym');
            if (! DB::selectOne('SELECT to_regclass(?) AS relation', [$name])->relation) {
                DB::statement('CREATE TABLE '.$name." PARTITION OF location_events FOR VALUES FROM ('".$from->format('Y-m-d')."') TO ('".$from->copy()->addMonth()->format('Y-m-d')."')");
            }
            $cutoff = now()->subDays($retention);
            DB::statement(<<<'SQL'
INSERT INTO route_summaries(game_session_player_id,simplified_geom,distance_meters,started_at,ended_at,generated_at)
SELECT player_id,ST_Simplify(line,0.00001),ST_Length(line::geography),started,ended,CURRENT_TIMESTAMP
FROM (
 SELECT p.id player_id,ST_MakeLine(e.geom ORDER BY e.client_timestamp,e.sequence,e.id) line,
 min(e.client_timestamp) started,max(e.client_timestamp) ended
 FROM (SELECT p.id FROM game_session_players p JOIN game_sessions s ON s.id=p.game_session_id
 WHERE s.status IN ('FINISHED','CANCELLED') AND NOT EXISTS(SELECT 1 FROM route_summaries r WHERE r.game_session_player_id=p.id)
 AND EXISTS(SELECT 1 FROM location_events e WHERE e.game_session_player_id=p.id)
 ORDER BY p.id LIMIT 20) p JOIN location_events e ON e.game_session_player_id=p.id
 GROUP BY p.id HAVING count(*) > 1
) routes
SQL);
            DB::selectOne("SELECT set_config('nexo.location_retention_cutoff',?,true)", [$cutoff->toISOString()]);
            DB::delete('DELETE FROM geofence_evaluations WHERE id IN (SELECT id FROM geofence_evaluations WHERE evaluated_at < ? ORDER BY id LIMIT 10000)', [$cutoff]);
            DB::delete('DELETE FROM location_events WHERE (id,server_received_at) IN (SELECT id,server_received_at FROM location_events WHERE server_received_at < ? ORDER BY server_received_at,id LIMIT 10000)', [$cutoff]);
            DB::delete('DELETE FROM player_latest_locations WHERE game_session_player_id IN (SELECT game_session_player_id FROM player_latest_locations WHERE server_received_at < ? LIMIT 10000)', [$cutoff]);
            DB::delete('DELETE FROM route_summaries WHERE id IN (SELECT id FROM route_summaries WHERE ended_at < ? LIMIT 10000)', [$cutoff]);
        });
        $this->info('Location partition and retention maintenance completed.');

        return self::SUCCESS;
    }
}

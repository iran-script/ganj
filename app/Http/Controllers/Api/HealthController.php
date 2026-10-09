<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController
{
    public function live(): JsonResponse
    {
        return response()->json(['data' => ['status' => 'ok']]);
    }

    public function ready(): JsonResponse
    {
        $checks = $this->checks();
        $ok = ! in_array('error', $checks, true);

        return response()->json(['data' => ['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks]], $ok ? 200 : 503);
    }

    public function check(): JsonResponse
    {
        $checks = $this->checks();
        $ok = ! in_array('error', $checks, true) && $checks['worker'] === 'ok';

        return response()->json(['data' => ['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks]]);
    }

    private function checks(): array
    {
        $checks = ['database' => 'error', 'postgis' => 'error', 'redis' => 'error', 'queue' => 'error', 'worker' => 'unknown'];
        try {
            DB::connection()->select('select 1');
            $checks['database'] = 'ok';
        } catch (\Throwable) {
        }
        try {
            DB::select('select PostGIS_Version()');
            $checks['postgis'] = 'ok';
        } catch (\Throwable) {
        }
        try {
            Redis::connection()->ping();
            $checks['redis'] = 'ok';
            $checks['queue'] = 'ok';
            $heartbeat = Redis::connection()->get('phase0:queue-worker-heartbeat');
            if ($heartbeat !== null && (int) $heartbeat >= now()->subSeconds(120)->timestamp) {
                $checks['worker'] = 'ok';
            }
        } catch (\Throwable) {
        }

        return $checks;
    }
}

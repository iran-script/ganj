<?php

namespace Tests\Feature;

use App\Jobs\Phase0SmokeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase0RegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_redis_queue_worker_and_health_endpoints(): void
    {
        $key = 'phase0-regression-'.Str::uuid();
        Phase0SmokeJob::dispatch($key)->onConnection('redis')->onQueue($key);
        Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $key, '--once' => true, '--tries' => 1]);
        $this->assertSame('processed', Cache::store('redis')->get($key));
        Cache::store('redis')->forget($key);
        $this->withHeader('X-Request-Id', 'phase0-regression')->getJson('/api/v1/live')->assertOk()->assertHeader('X-Request-Id', 'phase0-regression');
        $this->getJson('/api/v1/ready')->assertOk()->assertJsonPath('data.checks.database', 'ok')->assertJsonPath('data.checks.postgis', 'ok')->assertJsonPath('data.checks.redis', 'ok')->assertJsonPath('data.checks.queue', 'ok');
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.checks.worker', 'ok');
        $this->getJson('/api/v1/missing')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }
}

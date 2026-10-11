<?php

namespace Tests\Feature;

use App\Services\Outbox\OutboxDispatcher;
use App\Services\Outbox\OutboxPublisher;
use App\Services\Outbox\OutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_event_is_delivered_once_and_retained_after_duplicate_dispatch(): void
    {
        $stream = 'test:{outbox}:'.Str::uuid();
        config(['outbox.stream' => $stream]);
        try {
            $id = app(OutboxService::class)->record('runtime.node_completed.v1', 'runtime', 'node', (string) Str::uuid(), ['accepted' => true]);
            $this->assertDatabaseHas('outbox_events', ['event_id' => $id, 'status' => 'pending', 'published_at' => null]);
            $this->assertSame(0, Redis::connection()->xlen($stream));

            $this->assertSame(['published' => 1, 'failed' => 0], app(OutboxDispatcher::class)->dispatch());
            $this->assertSame(['published' => 0, 'failed' => 0], app(OutboxDispatcher::class)->dispatch());
            app(OutboxPublisher::class)->publish(DB::table('outbox_events')->where('event_id', $id)->sole());

            $this->assertSame(1, Redis::connection()->xlen($stream));
            $this->assertDatabaseHas('outbox_events', ['event_id' => $id, 'status' => 'published', 'attempts' => 1]);
            $this->assertNotNull(DB::table('outbox_events')->where('event_id', $id)->value('published_at'));
        } finally {
            Redis::connection()->del($stream, $stream.':published');
        }
    }

    public function test_delivery_before_ack_failure_retries_without_duplicate_publication(): void
    {
        $this->freezeTime();
        $stream = 'test:{outbox}:'.Str::uuid();
        config(['outbox.stream' => $stream]);
        try {
            $id = app(OutboxService::class)->record('runtime.node_completed.v1', 'runtime', 'node', (string) Str::uuid(), ['accepted' => true]);
            $publisher = new class extends OutboxPublisher
            {
                public function publish(object $event): void
                {
                    parent::publish($event);
                    throw new \RuntimeException('Simulated lost acknowledgement');
                }
            };

            $this->assertSame(['published' => 0, 'failed' => 1], (new OutboxDispatcher($publisher))->dispatch());

            $this->assertSame(1, Redis::connection()->xlen($stream));
            $this->assertDatabaseHas('outbox_events', ['event_id' => $id, 'status' => 'failed', 'attempts' => 1, 'published_at' => null, 'last_error' => \RuntimeException::class]);
            $this->assertSame(['published' => 0, 'failed' => 0], app(OutboxDispatcher::class)->dispatch());
            $this->travel(5)->seconds();

            $this->assertSame(['published' => 1, 'failed' => 0], app(OutboxDispatcher::class)->dispatch());

            $this->assertSame(1, Redis::connection()->xlen($stream));
            $this->assertDatabaseHas('outbox_events', ['event_id' => $id, 'status' => 'published', 'attempts' => 2, 'last_error' => null]);
        } finally {
            Redis::connection()->del($stream, $stream.':published');
        }
    }
}

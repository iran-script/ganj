<?php

namespace App\Services\Outbox;

use Illuminate\Support\Facades\Redis;

class OutboxPublisher
{
    /** Publish to the internal durable stream; consumers deduplicate the stable event_id. */
    public function publish(object $event): void
    {
        $stream = (string) config('outbox.stream', 'nexo:{outbox}:events');
        $script = <<<'LUA'
local previous = redis.call('HGET', KEYS[2], ARGV[1])
if previous then return previous end
local id = redis.call('XADD', KEYS[1], '*', 'event_id', ARGV[1], 'topic', ARGV[2], 'payload', ARGV[3])
redis.call('HSET', KEYS[2], ARGV[1], id)
return id
LUA;
        Redis::connection()->eval($script, 2, $stream, $stream.':published', $event->event_id, $event->topic, $event->payload);
    }
}

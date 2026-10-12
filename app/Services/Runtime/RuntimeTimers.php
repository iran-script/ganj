<?php

namespace App\Services\Runtime;

use App\Models\RuntimeTimer;
use App\Models\TeamNodeInstance;
use App\Models\TeamScenarioInstance;
use App\Models\User;
use Carbon\CarbonImmutable;

class RuntimeTimers
{
    public function __construct(private RuntimeEvents $events) {}

    public function start(TeamScenarioInstance $instance, ?TeamNodeInstance $node = null): RuntimeTimer
    {
        $timer = RuntimeTimer::where('team_scenario_instance_id', $instance->id)->where('node_instance_id', $node?->id)->first();
        if ($timer) {
            return $timer;
        }
        $timer = new RuntimeTimer;
        $timer->forceFill(['team_scenario_instance_id' => $instance->id, 'node_instance_id' => $node?->id,
            'timer_type' => $node ? ((($node->node->config['pause_behavior'] ?? 'PAUSE') === 'CONTINUE' || ($node->node->config['mode'] ?? '') === 'TIME_WINDOW') ? 'WALL_CLOCK' : 'NODE') : 'TEAM', 'started_at' => now()->startOfSecond(), 'status' => 'RUNNING',
            'duration_seconds' => $node && ($node->node->config['mode'] ?? '') === 'TIME_WINDOW'
                ? max(0, CarbonImmutable::parse($node->node->config['ends_at'])->timestamp - now()->timestamp)
                : ($node?->node->config['timeout_seconds'] ?? $node?->node->config['duration_seconds'] ?? null), 'accumulated_paused_seconds' => 0])->save();
        $this->events->record('timer_started', $instance, $node, data: ['timer_id' => $timer->public_id]);

        return $timer;
    }

    public function elapsed(RuntimeTimer $timer): int
    {
        if (! $timer->started_at) {
            return 0;
        }
        $end = $timer->finished_at ?? $timer->paused_at ?? now();

        return max(0, $end->timestamp - $timer->started_at->timestamp - $timer->accumulated_paused_seconds);
    }

    public function instanceElapsed(TeamScenarioInstance $instance): int
    {
        $timer = RuntimeTimer::where('team_scenario_instance_id', $instance->id)->whereNull('node_instance_id')->first();

        return $timer ? $this->elapsed($timer) : $instance->effective_elapsed_seconds;
    }

    public function pause(TeamScenarioInstance $instance, bool $pause, ?User $actor = null): void
    {
        foreach (RuntimeTimer::where('team_scenario_instance_id', $instance->id)->where('status', $pause ? 'RUNNING' : 'PAUSED')->lockForUpdate()->get() as $timer) {
            if ($timer->timer_type === 'WALL_CLOCK') {
                continue;
            }
            if ($pause) {
                $timer->forceFill(['status' => 'PAUSED', 'paused_at' => now()->startOfSecond()])->save();
            } else {
                $timer->forceFill(['status' => 'RUNNING', 'accumulated_paused_seconds' => $timer->accumulated_paused_seconds + max(0, now()->timestamp - $timer->paused_at->timestamp), 'paused_at' => null])->save();
            }
            $node = $timer->node_instance_id ? TeamNodeInstance::findOrFail($timer->node_instance_id) : null;
            $this->events->record($pause ? 'timer_paused' : 'timer_resumed', $instance, $node, $actor, data: ['timer_id' => $timer->public_id]);
        }
        $instance->forceFill(['effective_elapsed_seconds' => $this->instanceElapsed($instance)])->save();
    }

    public function finish(TeamScenarioInstance $instance, ?TeamNodeInstance $node = null, bool $cancel = false): void
    {
        $query = RuntimeTimer::where('team_scenario_instance_id', $instance->id)->whereIn('status', ['RUNNING', 'PAUSED']);
        if ($node) {
            $query->where('node_instance_id', $node->id);
        }
        foreach ($query->lockForUpdate()->get() as $timer) {
            $timer->forceFill(['status' => $cancel ? 'CANCELLED' : 'FINISHED', 'finished_at' => $timer->paused_at ?? now()->startOfSecond()])->save();
            if (! $cancel) {
                $this->events->record('timer_finished', $instance, $node, data: ['timer_id' => $timer->public_id]);
            }
        }
        $instance->forceFill(['effective_elapsed_seconds' => $this->instanceElapsed($instance)])->save();
    }
}

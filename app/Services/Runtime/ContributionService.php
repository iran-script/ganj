<?php

namespace App\Services\Runtime;

use App\Models\GameSessionPlayer;
use App\Models\RuntimeEvent;
use App\Models\TeamNodeInstance;
use Illuminate\Support\Collection;

class ContributionService
{
    /** @return Collection<int, RuntimeEvent> */
    public function forNode(TeamNodeInstance $node): Collection
    {
        return RuntimeEvent::where('aggregate_id', $node->public_id)
            ->where('event_type', 'runtime.node_submission_received.v1')
            ->where('payload->data->contribution', true)
            ->orderBy('id')
            ->get();
    }

    public function hasPlayer(TeamNodeInstance $node, GameSessionPlayer $player): bool
    {
        return $this->forNode($node)->contains(fn (RuntimeEvent $event): bool => $event->player_id === $player->id);
    }

    /** @return array{count:int,players:array<int,int>,roles:array<int,string>,within_window:bool} */
    public function summary(TeamNodeInstance $node, ?int $windowSeconds = null): array
    {
        $events = $this->forNode($node);
        $players = $events->pluck('player_id')->unique()->values()->all();
        $roles = $events->mapWithKeys(fn (RuntimeEvent $event): array => [$event->player_id => $event->payload['data']['role_code'] ?? 'UNKNOWN'])->all();
        $withinWindow = true;
        if ($windowSeconds !== null && $events->isNotEmpty()) {
            $times = $events->pluck('occurred_at')->map(fn ($time): int => $time->timestamp);
            $withinWindow = $times->max() - $times->min() <= $windowSeconds;
        }

        return ['count' => count($players), 'players' => $players, 'roles' => $roles, 'within_window' => $withinWindow];
    }
}

<?php

namespace App\Services\Scoring;

use App\Exceptions\ApiException;
use App\Models\ScoreEvent;
use App\Models\TeamScore;
use App\Models\User;
use App\Services\Games\GameScope;

class LeaderboardService
{
    public function __construct(private GameScope $scope) {}

    public function show(User $actor, string $sessionId): array
    {
        $session = $this->scope->resolve($actor, $sessionId);
        $scores = TeamScore::where('game_session_id', $session->id)->with('team.team')->get()
            ->sortBy([['score', 'desc'], ['effective_elapsed_seconds', 'asc'], ['hints_used', 'asc'], ['finished_at', 'asc'], ['team_id', 'asc']])->values();
        $playerTeamIds = $session->players()->where('user_id', $actor->id)->pluck('game_session_team_id');
        $operator = $this->scope->operator($actor, $session);
        if (! $operator && $playerTeamIds->isEmpty()) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'The requested Session was not found.', [], 404);
        }
        $visibility = $session->leaderboard_visibility ?? 'FULL';
        if ($visibility === 'HIDDEN_UNTIL_FINISH' && $session->status !== 'FINISHED') {
            return ['visibility' => $visibility, 'rankings' => []];
        }
        $rows = $scores->map(function (TeamScore $score, int $index) use ($playerTeamIds, $operator, $visibility): array {
            $own = $operator || $playerTeamIds->contains($score->team_id);
            $visible = $operator || $visibility === 'FULL' || $own;

            return ['rank' => $index + 1, 'team_id' => $visible ? $score->team->team->public_id : null, 'score' => $visible ? $score->score : null,
                'effective_elapsed_seconds' => $score->effective_elapsed_seconds, 'hints_used' => $score->hints_used,
                'finished_at' => $score->finished_at?->toISOString(), 'own_team' => $own];
        });
        if ($visibility === 'TOP_N') {
            $limit = max(1, (int) ($session->leaderboard_top_n ?? 10));
            $own = $rows->first(fn (array $row): bool => $row['own_team']);
            $rows = $rows->take($limit);
            if ($own && ! $rows->contains('own_team', true)) {
                $rows->push($own);
            }
        } elseif ($visibility === 'OWN_RANK_ONLY' && ! $operator) {
            $rows = $rows->filter(fn (array $row): bool => $row['own_team'])->values();
        }

        return ['visibility' => $visibility, 'rankings' => $rows->all()];
    }

    public function team(User $actor, string $sessionId, string $teamId): array
    {
        $session = $this->scope->resolve($actor, $sessionId);
        $registration = $session->teams()->whereHas('team', fn ($query) => $query->where('public_id', $teamId))->first();
        if (! $registration) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'The requested Team was not found.', [], 404);
        }
        $player = $session->players()->where('user_id', $actor->id)->where('game_session_team_id', $registration->id)->exists();
        if (! $player && ! $this->scope->operator($actor, $session)) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'The requested Team was not found.', [], 404);
        }
        $score = TeamScore::where(['game_session_id' => $session->id, 'team_id' => $registration->id])->first();
        if (! $score) {
            $score = new TeamScore;
            $score->forceFill(['game_session_id' => $session->id, 'team_id' => $registration->id, 'score' => 0, 'effective_elapsed_seconds' => 0, 'hints_used' => 0, 'updated_at' => now()])->save();
        }

        return ['team_id' => $registration->team->public_id, 'score' => $score->score, 'effective_elapsed_seconds' => $score->effective_elapsed_seconds, 'hints_used' => $score->hints_used, 'finished_at' => $score->finished_at?->toISOString(), 'finalized' => $score->finalized];
    }

    public function events(User $actor, string $sessionId, string $teamId): array
    {
        $session = $this->scope->resolve($actor, $sessionId);
        $registration = $session->teams()->whereHas('team', fn ($query) => $query->where('public_id', $teamId))->first();
        if (! $registration) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'The requested Team was not found.', [], 404);
        }
        $isMember = $session->players()->where('user_id', $actor->id)->where('game_session_team_id', $registration->id)->exists();
        if (! $isMember && ! $this->scope->operator($actor, $session)) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'The requested Team was not found.', [], 404);
        }

        return ScoreEvent::where('game_session_id', $session->id)->where('team_id', $registration->id)->latest('occurred_at')->get()->map(fn (ScoreEvent $event): array => ['event_id' => $event->event_id, 'source_type' => $event->source_type, 'delta' => $event->delta, 'reason_code' => $event->reason_code, 'occurred_at' => $event->occurred_at?->toISOString()])->all();
    }
}

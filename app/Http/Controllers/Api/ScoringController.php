<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\TeamScenarioInstance;
use App\Services\Games\GameScope;
use App\Services\Idempotency\IdempotencyStore;
use App\Services\Identity\DeviceResolver;
use App\Services\Scoring\HintService;
use App\Services\Scoring\LeaderboardService;
use App\Services\Scoring\ScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScoringController extends Controller
{
    public function __construct(private HintService $hints, private LeaderboardService $leaderboard, private ScoringService $scoring, private DeviceResolver $devices, private IdempotencyStore $idempotency) {}

    public function leaderboard(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->leaderboard->show($request->user(), $request->route('session_id'))])->header('Cache-Control', 'no-store');
    }

    public function team(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->leaderboard->team($request->user(), $request->route('session_id'), $request->route('team_id'))])->header('Cache-Control', 'no-store');
    }

    public function events(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->leaderboard->events($request->user(), $request->route('session_id'), $request->route('team_id'))])->header('Cache-Control', 'no-store');
    }

    public function hints(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->hints->list($request->user(), $request->route('session_id'), $request->route('team_id'))])->header('Cache-Control', 'no-store');
    }

    public function consumeHint(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->hints->consume($request->user(), $this->devices->fromRequest($request), $request->route('node_instance_id'), $request->all(), $request->header('Idempotency-Key', ''))])->header('Cache-Control', 'no-store');
    }

    public function adjust(Request $request): JsonResponse
    {
        $request->validate(['delta' => 'required|integer|between:-1000000,1000000|not_in:0', 'reason' => 'required|string|max:1000']);
        $idempotencyKey = $request->header('Idempotency-Key', '');
        if ($idempotencyKey === '') {
            throw new ApiException('VALIDATION_FAILED', 'Idempotency-Key is required.', [], 422);
        }
        $actor = $request->user();
        $session = app(GameScope::class)->resolve($actor, $request->route('session_id'), true, true);
        if (! app(GameScope::class)->operator($actor, $session)) {
            throw new ApiException('AUTHORIZATION_DENIED', 'Score adjustment requires assigned operational access.', [], 403);
        }
        $registration = $session->teams()->whereHas('team', fn ($query) => $query->where('public_id', $request->route('team_id')))->first();
        if (! $registration) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'The requested Team was not found.', [], 404);
        }
        $instance = TeamScenarioInstance::where('game_session_team_id', $registration->id)->firstOrFail();
        $fingerprint = hash('sha256', json_encode(['actor' => $actor->public_id, 'session' => $session->public_id, 'team' => $registration->team->public_id,
            'delta' => $request->integer('delta'), 'reason' => $request->string('reason')->toString()], JSON_THROW_ON_ERROR));
        $key = 'score-adjust:'.hash('sha256', $idempotencyKey);
        $reserved = $this->idempotency->reserve($key, $fingerprint);
        if ($reserved['status'] === 'completed') {
            return response()->json($reserved['response'])->header('Cache-Control', 'no-store');
        }
        if ($reserved['status'] !== 'reserved') {
            throw new ApiException('CONFLICT', 'The score adjustment is still processing.', [], 409);
        }
        $event = $this->scoring->apply($instance, null, null, (int) $request->integer('delta'), 'MANUAL_ADJUSTMENT', $request->string('reason')->toString(), 'manual:'.$idempotencyKey, [], $actor->id, $request->string('reason')->toString());
        $response = ['data' => ['event_id' => $event->event_id, 'delta' => $event->delta]];
        $this->idempotency->complete($key, $fingerprint, 200, $response);

        return response()->json($response)->header('Cache-Control', 'no-store');
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GameMaster\LiveChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameMasterController extends Controller
{
    public function __construct(private LiveChallengeService $service) {}

    public function controlRoom(Request $request, string $session_id): JsonResponse
    {
        return response()->json(['data' => $this->service->controlRoom($request->user(), $session_id)])->header('Cache-Control', 'no-store');
    }

    public function createChallenge(Request $request, string $session_id): JsonResponse
    {
        $input = $request->validate(['type' => 'required|string', 'targets' => 'required|array|min:1|max:8', 'targets.*.type' => 'required|string', 'targets.*.id' => 'required|uuid',
            'message' => 'sometimes|string|max:10000', 'objective' => 'sometimes|string|max:10000', 'duration_seconds' => 'sometimes|integer|min:1|max:86400', 'expires_at' => 'sometimes|date',
            'reward' => 'sometimes|array', 'penalty' => 'sometimes|array', 'interrupt_policy' => 'sometimes|in:PARALLEL,SOFT_INTERRUPT', 'fairness_class' => 'sometimes|string']);

        return response()->json(['data' => $this->service->create($request->user(), $session_id, $input, $request->header('Idempotency-Key', ''))], 201)->header('Cache-Control', 'no-store');
    }

    public function listChallenges(Request $request, string $session_id): JsonResponse
    {
        return response()->json(['data' => $this->service->list($request->user(), $session_id)])->header('Cache-Control', 'no-store');
    }

    public function showChallenge(Request $request, string $challenge_id): JsonResponse
    {
        return response()->json(['data' => $this->service->show($request->user(), $challenge_id)])->header('Cache-Control', 'no-store');
    }

    public function transition(Request $request, string $challenge_id, string $action): JsonResponse
    {
        $input = $request->validate(['acknowledged' => 'sometimes|boolean', 'reason' => 'sometimes|string|max:1000']);

        return response()->json(['data' => $this->service->transition($request->user(), $challenge_id, $action, $input, $request->header('Idempotency-Key', ''))])->header('Cache-Control', 'no-store');
    }

    public function message(Request $request, string $session_id): JsonResponse
    {
        $input = $request->validate(['target_type' => 'required|in:PLAYER,TEAM,SESSION', 'target_id' => 'required|uuid', 'body' => 'required|string|max:10000',
            'priority' => 'sometimes|in:NORMAL,EMERGENCY', 'reason' => 'sometimes|string|max:1000']);

        return response()->json(['data' => $this->service->message($request->user(), $session_id, $input, $request->header('Idempotency-Key', ''))])->header('Cache-Control', 'no-store');
    }

    public function rebindDevice(Request $request, string $session_id, string $player_id): JsonResponse
    {
        $input = $request->validate(['device_id' => 'required|uuid', 'reason' => 'required|string|max:1000']);

        return response()->json(['data' => $this->service->rebindDevice($request->user(), $session_id, $player_id, $input, $request->header('Idempotency-Key', ''))])->header('Cache-Control', 'no-store');
    }

    public function disqualify(Request $request, string $session_id, string $player_id): JsonResponse
    {
        $input = $request->validate(['reason' => 'required|string|max:1000']);

        return response()->json(['data' => $this->service->disqualify($request->user(), $session_id, $player_id, $input, $request->header('Idempotency-Key', ''))])->header('Cache-Control', 'no-store');
    }
}

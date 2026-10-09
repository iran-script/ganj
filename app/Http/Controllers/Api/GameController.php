<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GameRequest;
use App\Models\Game;
use App\Services\Games\GameScope;
use App\Services\Games\GameService;
use Illuminate\Http\JsonResponse;

class GameController extends Controller
{
    public function __construct(private GameScope $scope, private GameService $service) {}

    public function index(GameRequest $request): JsonResponse
    {
        $input = $request->validated();
        $page = $this->scope->games($request->user())->orderBy('public_id')->cursorPaginate($input['limit'] ?? 25, ['*'], 'cursor', $input['cursor'] ?? null);

        return response()->json(['data' => $page->getCollection()->map(fn (Game $g): array => $g->publicProfile())->all(), 'meta' => ['pagination' => ['cursor' => $input['cursor'] ?? null, 'next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]]])->header('Cache-Control', 'no-store');
    }

    public function show(GameRequest $request, string $game_id): JsonResponse
    {
        $request->validated();

        return response()->json(['data' => $this->scope->resolve($request->user(), $game_id)->publicProfile()])->header('Cache-Control', 'no-store');
    }

    public function create(GameRequest $request): JsonResponse
    {
        return $this->mutate($request, 'create', null, 201);
    }

    public function update(GameRequest $request, string $game_id): JsonResponse
    {
        return $this->mutate($request, 'update', $game_id);
    }

    public function archive(GameRequest $request, string $game_id): JsonResponse
    {
        return $this->mutate($request, 'archive', $game_id);
    }

    private function mutate(GameRequest $request, string $action, ?string $id, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->service->execute($request->user(), $action, $request->validated(), $id, $request->header('Idempotency-Key'))], $status)->header('Cache-Control', 'no-store');
    }
}

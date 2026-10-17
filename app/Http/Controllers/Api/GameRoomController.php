<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GameRoomRequest;
use App\Models\GameRoom;
use App\Services\Games\GameRoomService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class GameRoomController extends Controller
{
    public function __construct(private GameRoomService $rooms) {}

    public function index(GameRoomRequest $request, string $game_id): JsonResponse
    {
        $page = $this->rooms->listing($request->user(), $game_id, $request->validated());
        $data = $page->getCollection()->map(fn (GameRoom $room): array => $this->rooms->show($request->user(), $room->public_id))->all();

        return response()->json(['data' => $data, 'meta' => ['pagination' => ['cursor' => $request->input('cursor'), 'next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]]])->header('Cache-Control', 'no-store');
    }

    public function show(GameRoomRequest $request, string $room_id): JsonResponse
    {
        return response()->json(['data' => $this->rooms->show($request->user(), $room_id)])->header('Cache-Control', 'no-store');
    }

    public function create(GameRoomRequest $request, string $game_id): JsonResponse
    {
        return response()->json(['data' => $this->rooms->create($request->user(), $game_id, $request->validated(), $request->header('Idempotency-Key'))], 201)->header('Cache-Control', 'no-store');
    }

    public function addTeam(GameRoomRequest $request, string $room_id): Response
    {
        return response()->json(['data' => $this->rooms->addTeam($request->user(), $room_id, $request->validated(), $request->header('Idempotency-Key'))])->header('Cache-Control', 'no-store');
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminManagementRequest;
use App\Models\User;
use App\Services\Management\ManagementService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PlayerManagementController extends Controller
{
    public function __construct(private ManagementService $management) {}

    public function index(AdminManagementRequest $request): JsonResponse
    {
        $page = $this->management->players($request->user(), $request->validated());

        return response()->json(['data' => $page->getCollection()->map(fn (User $user): array => $this->management->playerListProfile($request->user(), $user))->all(), 'meta' => ['pagination' => [
            'cursor' => $request->input('cursor'), 'next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages(),
        ]]])->header('Cache-Control', 'no-store');
    }

    public function show(AdminManagementRequest $request, string $user_id): JsonResponse
    {
        return response()->json(['data' => $this->management->player($request->user(), $user_id)])->header('Cache-Control', 'no-store');
    }

    public function update(AdminManagementRequest $request, string $user_id): JsonResponse
    {
        return response()->json(['data' => $this->management->updatePlayer($request->user(), $user_id, $request->validated())])->header('Cache-Control', 'no-store');
    }

    public function status(AdminManagementRequest $request, string $user_id): JsonResponse
    {
        return response()->json(['data' => $this->management->setPlayerStatus($request->user(), $user_id, $request->validated())])->header('Cache-Control', 'no-store');
    }

    public function password(AdminManagementRequest $request, string $user_id): Response
    {
        $this->management->resetPlayerPassword($request->user(), $user_id, $request->validated());

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function delete(AdminManagementRequest $request, string $user_id): Response
    {
        $this->management->deletePlayer($request->user(), $user_id, $request->validated()['reason']);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function restore(AdminManagementRequest $request, string $user_id): JsonResponse
    {
        return response()->json(['data' => $this->management->restorePlayer($request->user(), $user_id, $request->validated()['reason'])])->header('Cache-Control', 'no-store');
    }

    public function addToTeam(AdminManagementRequest $request, string $user_id): JsonResponse
    {
        return response()->json(['data' => $this->management->addPlayerToTeam($request->user(), $user_id, $request->validated()['team_id'], $request->validated()['reason'])])->header('Cache-Control', 'no-store');
    }

    public function removeFromTeam(AdminManagementRequest $request, string $user_id, string $team_id): Response
    {
        $this->management->removePlayerFromTeam($request->user(), $user_id, $team_id, $request->validated()['reason']);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}

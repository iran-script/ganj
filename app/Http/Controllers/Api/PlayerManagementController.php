<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminManagementRequest;
use App\Models\User;
use App\Services\Management\ManagementService;
use Illuminate\Http\JsonResponse;

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
}

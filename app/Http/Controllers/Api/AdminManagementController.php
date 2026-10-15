<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminManagementRequest;
use App\Models\User;
use App\Services\Management\ManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Symfony\Component\HttpFoundation\Response;

class AdminManagementController extends Controller
{
    public function __construct(private ManagementService $management) {}

    public function users(AdminManagementRequest $request): JsonResponse
    {
        return $this->collection($request, $this->management->users($request->user(), $request->validated()), fn (User $user): array => $this->management->listProfile($user));
    }

    public function user(AdminManagementRequest $request, string $user_id): JsonResponse
    {
        return response()->json(['data' => $this->management->user($request->user(), $user_id)])->header('Cache-Control', 'no-store');
    }

    public function setStatus(AdminManagementRequest $request, string $user_id): JsonResponse
    {
        return response()->json(['data' => $this->management->setStatus($request->user(), $user_id, $request->validated())])->header('Cache-Control', 'no-store');
    }

    public function administrators(AdminManagementRequest $request): JsonResponse
    {
        return $this->collection($request, $this->management->administrators($request->user(), $request->validated()), fn (User $user): array => $this->management->administratorProfile($user));
    }

    public function grantAdministrator(AdminManagementRequest $request, string $user_id): JsonResponse
    {
        return response()->json(['data' => $this->management->grantAdministrator($request->user(), $user_id, $request->validated()['reason'])], 201)->header('Cache-Control', 'no-store');
    }

    public function revokeAdministrator(AdminManagementRequest $request, string $user_id): Response
    {
        $this->management->revokeAdministrator($request->user(), $user_id, $request->validated()['reason']);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function roles(AdminManagementRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->management->roleOverview($request->user())])->header('Cache-Control', 'no-store');
    }

    public function capabilities(AdminManagementRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->management->capabilities($request->user())])->header('Cache-Control', 'no-store');
    }

    private function collection(AdminManagementRequest $request, CursorPaginator $page, callable $map): JsonResponse
    {
        return response()->json(['data' => $page->getCollection()->map($map)->all(), 'meta' => ['pagination' => [
            'cursor' => $request->input('cursor'), 'next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages(),
        ]]])->header('Cache-Control', 'no-store');
    }
}

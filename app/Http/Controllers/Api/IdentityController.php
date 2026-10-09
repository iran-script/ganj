<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IdentityRequest;
use App\Models\UserDevice;
use App\Services\Identity\IdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class IdentityController extends Controller
{
    public function __construct(private IdentityService $identity) {}

    public function register(IdentityRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->identity->register($request->validated(), $request)], 201)->header('Cache-Control', 'no-store');
    }

    public function login(IdentityRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->identity->login($request->validated(), $request)])->header('Cache-Control', 'no-store');
    }

    public function me(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('user.read_self');

        return response()->json(['data' => $request->user()->publicProfile()])->header('Cache-Control', 'no-store');
    }

    public function update(IdentityRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('user.update_self');

        return response()->json(['data' => $this->identity->updateProfile($request->user(), $request->validated())->publicProfile()])->header('Cache-Control', 'no-store');
    }

    public function devices(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('device.list_self');
        $input = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['sometimes', 'nullable', 'string']]);
        $page = $request->user()->devices()->orderBy('public_id')->cursorPaginate($input['limit'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn (UserDevice $device) => $device->publicProfile()), 'meta' => ['pagination' => ['cursor' => $input['cursor'] ?? null, 'next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]]])->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, string $device_id): Response
    {
        Gate::forUser($request->user())->authorize('device.revoke_self');
        $this->identity->revoke($request->user(), $device_id);

        return response()->noContent();
    }

    public function logout(Request $request): Response
    {
        $this->identity->logout($request->user());

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationRequest;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Services\Organizations\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Symfony\Component\HttpFoundation\Response;

class OrganizationController extends Controller
{
    public function __construct(private OrganizationService $organizations) {}

    public function index(OrganizationRequest $request): JsonResponse
    {
        $page = $this->organizations->listing($request->user(), $request->validated());

        return $this->collection($page, $request, $page->getCollection()->map(fn (Organization $organization) => $organization->publicProfile())->all());
    }

    public function create(OrganizationRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->organizations->create($request->user(), $request->validated())->publicProfile()], 201)->header('Cache-Control', 'no-store');
    }

    public function show(OrganizationRequest $request, string $organization_id): JsonResponse
    {
        return response()->json(['data' => $this->organizations->show($request->user(), $organization_id)])->header('Cache-Control', 'no-store');
    }

    public function update(OrganizationRequest $request, string $organization_id): JsonResponse
    {
        return response()->json(['data' => $this->organizations->update($request->user(), $organization_id, $request->validated())->publicProfile()])->header('Cache-Control', 'no-store');
    }

    public function members(OrganizationRequest $request, string $organization_id): JsonResponse
    {
        $page = $this->organizations->members($request->user(), $organization_id, $request->validated());

        return $this->collection($page, $request, $page->getCollection()->map(fn (OrganizationMember $member) => $member->publicProfile())->all());
    }

    public function addMember(OrganizationRequest $request, string $organization_id): JsonResponse
    {
        return response()->json(['data' => $this->organizations->addMember($request->user(), $organization_id, $request->validated())->publicProfile()], 201)->header('Cache-Control', 'no-store');
    }

    public function removeMember(OrganizationRequest $request, string $organization_id, string $user_id): Response
    {
        $this->organizations->removeMember($request->user(), $organization_id, $user_id, $request->validated());

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    private function collection(CursorPaginator $page, OrganizationRequest $request, array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['pagination' => ['cursor' => $request->input('cursor'), 'next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]]])->header('Cache-Control', 'no-store');
    }
}
